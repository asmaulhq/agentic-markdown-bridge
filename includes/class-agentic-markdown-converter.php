<?php
/**
 * HTML to Markdown conversion for Agentic Markdown Bridge.
 *
 * @package AgenticMarkdownBridge
 */

defined( 'ABSPATH' ) || exit;

final class Agentic_Markdown_Converter {
	/**
	 * Convert rendered HTML to readable Markdown.
	 *
	 * @param string $html     Rendered HTML.
	 * @param string   $base_url        Base URL for relative links.
	 * @param string[] $exclude_classes Additional CSS classes to remove.
	 * @return string
	 */
	public static function convert( $html, $base_url = '', $exclude_classes = array() ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return '';
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			$text = wp_strip_all_tags( $html, true );
			$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			return (string) preg_replace( "/\n{3,}/", "\n\n", trim( $text ) );
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument( '1.0', 'UTF-8' );
		$wrapper  = '<div id="agentic-markdown-bridge-root">' . $html . '</div>';
		$flags    = 0;

		if ( defined( 'LIBXML_HTML_NOIMPLIED' ) ) {
			$flags |= LIBXML_HTML_NOIMPLIED;
		}
		if ( defined( 'LIBXML_HTML_NODEFDTD' ) ) {
			$flags |= LIBXML_HTML_NODEFDTD;
		}

		$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $wrapper, $flags );
		if ( ! $loaded ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			return trim( wp_strip_all_tags( $html, true ) );
		}

		$xpath = new DOMXPath( $dom );
		$remove_queries = array(
			'//script',
			'//style',
			'//noscript',
			'//form',
			'//button',
			'//svg',
			'//canvas',
			'//template',
			'//nav',
			'//*[@aria-hidden="true"]',
			'//*[@hidden]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " screen-reader-text ")]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " agentic-markdown-exclude ")]',
			'//*[@data-agentic-markdown="exclude"]',
		);

		$custom_class_conditions = array();
		foreach ( (array) $exclude_classes as $class ) {
			$class = preg_replace( '/[^A-Za-z0-9_-]/', '', ltrim( trim( (string) $class ), '.' ) );
			if ( '' !== $class ) {
				$custom_class_conditions[] = 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
			}
		}
		if ( $custom_class_conditions ) {
			$remove_queries[] = '//*[' . implode( ' or ', array_values( array_unique( $custom_class_conditions ) ) ) . ']';
		}

		foreach ( $remove_queries as $query ) {
			$nodes = $xpath->query( $query );
			if ( ! $nodes ) {
				continue;
			}
			$to_remove = array();
			foreach ( $nodes as $node ) {
				$to_remove[] = $node;
			}
			foreach ( $to_remove as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		$root = $dom->getElementById( 'agentic-markdown-bridge-root' );
		if ( ! $root ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			return '';
		}

		$markdown = self::render_children( $root, $base_url );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$markdown = html_entity_decode( $markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$markdown = str_replace( "\xC2\xA0", ' ', $markdown );
		$markdown = (string) preg_replace( "/[ \t]+\n/", "\n", $markdown );
		$markdown = (string) preg_replace( "/\n[ \t]+/", "\n", $markdown );
		$markdown = (string) preg_replace( "/\n{3,}/", "\n\n", $markdown );
		$markdown = (string) preg_replace( '/ {2,}/u', ' ', $markdown );

		return trim( $markdown );
	}

	/**
	 * Render all children.
	 *
	 * @param DOMNode $node     Node.
	 * @param string  $base_url Base URL.
	 * @return string
	 */
	private static function render_children( DOMNode $node, $base_url = '' ) {
		$output   = '';
		$previous = null;

		foreach ( $node->childNodes as $child ) {
			$piece = self::render_node( $child, $base_url );
			if ( '' === $piece ) {
				continue;
			}

			$output   = self::append_rendered_piece( $output, $piece, $previous, $child );
			$previous = $child;
		}

		return $output;
	}

	/**
	 * Render inline children with spacing between adjacent inline elements when
	 * the source markup contains no literal whitespace. This keeps constructs
	 * such as <em>Experience</em><strong>10+ Years</strong> readable.
	 *
	 * @param DOMNode $node     Node.
	 * @param string  $base_url Base URL.
	 * @return string
	 */
	private static function render_inline_children( DOMNode $node, $base_url = '' ) {
		return self::render_children( $node, $base_url );
	}

	/**
	 * Append a rendered child while preserving readable separation between
	 * adjacent inline elements. HTML does not insert visual whitespace between
	 * inline siblings, but page builders commonly use adjacent wrappers for
	 * label/value pairs. Without a separator those become invalid/ambiguous
	 * Markdown such as *Global Reach***8+ Countries**.
	 *
	 * Text-node boundaries are intentionally left untouched so legitimate
	 * constructs such as H<sub>2</sub>O or word<strong>s</strong> keep their
	 * author-defined spacing.
	 *
	 * @param string       $output        Existing rendered output.
	 * @param string       $piece         Newly rendered piece.
	 * @param DOMNode|null $previous_node Previous non-empty source node.
	 * @param DOMNode      $current_node  Current source node.
	 * @return string
	 */
	private static function append_rendered_piece( $output, $piece, $previous_node, DOMNode $current_node ) {
		if ( '' === $output || null === $previous_node ) {
			return $output . $piece;
		}

		if ( self::needs_inline_separator( $output, $piece, $previous_node, $current_node ) ) {
			$output .= ' ';
		}

		return $output . $piece;
	}

	/**
	 * Determine whether adjacent source elements need one Markdown space.
	 *
	 * @param string  $output        Existing output.
	 * @param string  $piece         Current rendered piece.
	 * @param DOMNode $previous_node Previous source node.
	 * @param DOMNode $current_node  Current source node.
	 * @return bool
	 */
	private static function needs_inline_separator( $output, $piece, DOMNode $previous_node, DOMNode $current_node ) {
		if ( XML_ELEMENT_NODE !== $previous_node->nodeType || XML_ELEMENT_NODE !== $current_node->nodeType ) {
			return false;
		}

		if ( ! self::is_inline_element( $previous_node ) || ! self::is_inline_element( $current_node ) ) {
			return false;
		}

		// Superscript/subscript are commonly attached directly to preceding text.
		$current_tag = strtolower( $current_node->nodeName );
		if ( in_array( $current_tag, array( 'sub', 'sup' ), true ) ) {
			return false;
		}

		if ( preg_match( '/\s$/u', $output ) || preg_match( '/^\s/u', $piece ) ) {
			return false;
		}

		$current_text = trim( (string) $current_node->textContent );
		if ( '' !== $current_text && preg_match( '/^[\p{P}]/u', $current_text ) ) {
			return false;
		}

		$previous_text = trim( (string) $previous_node->textContent );
		if ( '' !== $previous_text && preg_match( '/[\(\[\{\/\-]$/u', $previous_text ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a DOM element behaves as inline content for spacing purposes.
	 *
	 * @param DOMNode $node Node.
	 * @return bool
	 */
	private static function is_inline_element( DOMNode $node ) {
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return false;
		}

		return in_array(
			strtolower( $node->nodeName ),
			array(
				'a',
				'abbr',
				'b',
				'bdi',
				'bdo',
				'cite',
				'code',
				'del',
				'em',
				'i',
				'img',
				'kbd',
				'mark',
				'q',
				's',
				'samp',
				'small',
				'span',
				'strong',
				'sub',
				'sup',
				'time',
				'u',
				'var',
			),
			true
		);
	}

	/**
	 * Render one DOM node.
	 *
	 * @param DOMNode $node     Node.
	 * @param string  $base_url Base URL.
	 * @return string
	 */
	private static function render_node( DOMNode $node, $base_url = '' ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = str_replace( "\xC2\xA0", ' ', (string) $node->nodeValue );
			return (string) preg_replace( '/[\t\r\n ]+/u', ' ', $text );
		}

		if ( XML_COMMENT_NODE === $node->nodeType || XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$tag = strtolower( $node->nodeName );

		if ( preg_match( '/^h([1-6])$/', $tag, $matches ) ) {
			$level = (int) $matches[1];
			$text  = trim( self::render_inline_children( $node, $base_url ) );
			return $text ? "\n\n" . str_repeat( '#', $level ) . ' ' . $text . "\n\n" : '';
		}

		switch ( $tag ) {
			case 'p':
				$text = trim( self::render_inline_children( $node, $base_url ) );
				return $text ? "\n\n" . $text . "\n\n" : '';

			case 'br':
				return "  \n";

			case 'strong':
			case 'b':
				$text = trim( self::render_inline_children( $node, $base_url ) );
				return $text ? '**' . $text . '**' : '';

			case 'em':
			case 'i':
				$text = trim( self::render_inline_children( $node, $base_url ) );
				return $text ? '*' . $text . '*' : '';

			case 'del':
			case 's':
				$text = trim( self::render_inline_children( $node, $base_url ) );
				return $text ? '~~' . $text . '~~' : '';

			case 'mark':
			case 'small':
			case 'cite':
			case 'time':
			case 'abbr':
				return self::render_inline_children( $node, $base_url );

			case 'sup':
				$text = trim( self::render_inline_children( $node, $base_url ) );
				return $text ? '^' . $text . '^' : '';

			case 'sub':
				$text = trim( self::render_inline_children( $node, $base_url ) );
				return $text ? '~' . $text . '~' : '';

			case 'kbd':
				$text = trim( $node->textContent );
				return $text ? '`' . str_replace( '`', '\\`', $text ) . '`' : '';

			case 'a':
				$text = trim( self::render_inline_children( $node, $base_url ) );
				$href = $node instanceof DOMElement ? trim( $node->getAttribute( 'href' ) ) : '';
				if ( ! $href ) {
					return $text;
				}
				$href = self::absolute_url( $href, $base_url );
				if ( ! $text ) {
					$text = $href;
				}
				$title = $node instanceof DOMElement ? trim( $node->getAttribute( 'title' ) ) : '';
				$dest  = self::escape_link_destination( $href );
				if ( $title ) {
					$dest .= ' "' . str_replace( '"', '\\"', $title ) . '"';
				}
				return '[' . self::escape_link_text( $text ) . '](' . $dest . ')';

			case 'img':
				if ( ! $node instanceof DOMElement ) {
					return '';
				}
				$src = trim( $node->getAttribute( 'src' ) );
				if ( ! $src ) {
					$src = trim( $node->getAttribute( 'data-src' ) );
				}
				if ( ! $src ) {
					return '';
				}
				$alt   = trim( $node->getAttribute( 'alt' ) );
				$title = trim( $node->getAttribute( 'title' ) );
				$src   = self::absolute_url( $src, $base_url );
				$dest  = self::escape_link_destination( $src );
				if ( $title ) {
					$dest .= ' "' . str_replace( '"', '\\"', $title ) . '"';
				}
				return '![' . self::escape_link_text( $alt ) . '](' . $dest . ')';

			case 'ul':
				return "\n\n" . self::render_list( $node, false, 0, $base_url ) . "\n\n";

			case 'ol':
				return "\n\n" . self::render_list( $node, true, 0, $base_url ) . "\n\n";

			case 'blockquote':
				$text = trim( self::render_children( $node, $base_url ) );
				if ( ! $text ) {
					return '';
				}
				$text = (string) preg_replace( '/^/m', '> ', $text );
				return "\n\n" . $text . "\n\n";

			case 'pre':
				$text  = rtrim( (string) $node->textContent, "\r\n" );
				$lang  = '';
				if ( $node instanceof DOMElement ) {
					$class = $node->getAttribute( 'class' );
					if ( preg_match( '/(?:language|lang)-([a-z0-9_+-]+)/i', $class, $m ) ) {
						$lang = $m[1];
					}
				}
				$fence = false !== strpos( $text, '```' ) ? '````' : '```';
				return "\n\n" . $fence . $lang . "\n" . $text . "\n" . $fence . "\n\n";

			case 'code':
				$text = trim( (string) $node->textContent );
				if ( ! $text ) {
					return '';
				}
				$fence = false !== strpos( $text, '`' ) ? '``' : '`';
				return $fence . $text . $fence;

			case 'hr':
				return "\n\n---\n\n";

			case 'table':
				return "\n\n" . self::render_table( $node, $base_url ) . "\n\n";

			case 'details':
				return self::render_details( $node, $base_url );

			case 'figure':
				return self::render_figure( $node, $base_url );

			case 'figcaption':
				$text = trim( self::render_inline_children( $node, $base_url ) );
				return $text ? "\n\n_" . $text . "_\n\n" : '';

			case 'dl':
				return self::render_definition_list( $node, $base_url );

			case 'dt':
				$text = trim( self::render_inline_children( $node, $base_url ) );
				return $text ? "\n\n**" . $text . "**\n" : '';

			case 'dd':
				$text = trim( self::render_children( $node, $base_url ) );
				return $text ? $text . "\n\n" : '';

			case 'iframe':
			case 'video':
			case 'audio':
				if ( $node instanceof DOMElement ) {
					$src = trim( $node->getAttribute( 'src' ) );
					if ( ! $src ) {
						$source = $node->getElementsByTagName( 'source' )->item( 0 );
						if ( $source instanceof DOMElement ) {
							$src = trim( $source->getAttribute( 'src' ) );
						}
					}
					if ( $src ) {
						$src = self::absolute_url( $src, $base_url );
						return "\n\n[Embedded media](" . self::escape_link_destination( $src ) . ")\n\n";
					}
				}
				return '';

			case 'address':
				$text = trim( self::render_inline_children( $node, $base_url ) );
				return $text ? "\n\n" . $text . "\n\n" : '';

			case 'div':
			case 'section':
			case 'article':
			case 'main':
			case 'header':
			case 'footer':
			case 'span':
			default:
				return self::render_children( $node, $base_url );
		}
	}

	/**
	 * Render lists.
	 */
	private static function render_list( DOMNode $list, $ordered, $depth, $base_url ) {
		$lines = array();
		$index = 1;

		foreach ( $list->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) {
				continue;
			}

			$inline          = '';
			$nested          = array();
			$previous_inline = null;
			foreach ( $child->childNodes as $li_child ) {
				if ( XML_ELEMENT_NODE === $li_child->nodeType && in_array( strtolower( $li_child->nodeName ), array( 'ul', 'ol' ), true ) ) {
					$nested[] = array(
						'node'    => $li_child,
						'ordered' => 'ol' === strtolower( $li_child->nodeName ),
					);
					continue;
				}

				$piece = self::render_node( $li_child, $base_url );
				if ( '' === $piece ) {
					continue;
				}

				$inline          = self::append_rendered_piece( $inline, $piece, $previous_inline, $li_child );
				$previous_inline = $li_child;
			}

			$inline = trim( (string) preg_replace( '/\s+/u', ' ', $inline ) );
			$marker = $ordered ? $index . '.' : '-';
			$lines[] = str_repeat( '  ', $depth ) . $marker . ( $inline ? ' ' . $inline : '' );

			foreach ( $nested as $nested_list ) {
				$nested_rendered = self::render_list( $nested_list['node'], $nested_list['ordered'], $depth + 1, $base_url );
				if ( $nested_rendered ) {
					$lines[] = $nested_rendered;
				}
			}
			++$index;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Render a table into GitHub-style Markdown.
	 */
	private static function render_table( DOMNode $table, $base_url ) {
		$rows = array();
		foreach ( $table->childNodes as $section_or_row ) {
			if ( XML_ELEMENT_NODE !== $section_or_row->nodeType ) {
				continue;
			}
			$name = strtolower( $section_or_row->nodeName );
			if ( 'tr' === $name ) {
				$rows[] = self::extract_table_row( $section_or_row, $base_url );
			} elseif ( in_array( $name, array( 'thead', 'tbody', 'tfoot' ), true ) ) {
				foreach ( $section_or_row->childNodes as $row ) {
					if ( XML_ELEMENT_NODE === $row->nodeType && 'tr' === strtolower( $row->nodeName ) ) {
						$rows[] = self::extract_table_row( $row, $base_url );
					}
				}
			}
		}

		$rows = array_values( array_filter( $rows ) );
		if ( ! $rows ) {
			return '';
		}

		$columns = 0;
		foreach ( $rows as $row ) {
			$columns = max( $columns, count( $row ) );
		}
		if ( 0 === $columns ) {
			return '';
		}

		foreach ( $rows as &$row ) {
			$row = array_pad( $row, $columns, '' );
		}
		unset( $row );

		$lines   = array();
		$lines[] = '| ' . implode( ' | ', $rows[0] ) . ' |';
		$lines[] = '| ' . implode( ' | ', array_fill( 0, $columns, '---' ) ) . ' |';
		foreach ( array_slice( $rows, 1 ) as $row ) {
			$lines[] = '| ' . implode( ' | ', $row ) . ' |';
		}

		return implode( "\n", $lines );
	}

	/** Extract table row cells. */
	private static function extract_table_row( DOMNode $row, $base_url ) {
		$cells = array();
		foreach ( $row->childNodes as $cell ) {
			if ( XML_ELEMENT_NODE !== $cell->nodeType || ! in_array( strtolower( $cell->nodeName ), array( 'td', 'th' ), true ) ) {
				continue;
			}
			$text = trim( (string) preg_replace( '/\s+/u', ' ', self::render_inline_children( $cell, $base_url ) ) );
			$text = str_replace( '|', '\\|', $text );
			$cells[] = $text;
		}
		return $cells;
	}

	/** Render details/summary blocks. */
	private static function render_details( DOMNode $details, $base_url ) {
		$summary = '';
		$body    = '';
		foreach ( $details->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && 'summary' === strtolower( $child->nodeName ) ) {
				$summary = trim( self::render_inline_children( $child, $base_url ) );
			} else {
				$body .= self::render_node( $child, $base_url );
			}
		}
		$body = trim( $body );
		if ( ! $summary && ! $body ) {
			return '';
		}
		$output = '';
		if ( $summary ) {
			$output .= "\n\n### " . $summary . "\n\n";
		}
		if ( $body ) {
			$output .= $body . "\n\n";
		}
		return $output;
	}

	/** Render figure with caption. */
	private static function render_figure( DOMNode $figure, $base_url ) {
		$content = '';
		$caption = '';
		foreach ( $figure->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && 'figcaption' === strtolower( $child->nodeName ) ) {
				$caption = trim( self::render_inline_children( $child, $base_url ) );
			} else {
				$content .= self::render_node( $child, $base_url );
			}
		}
		$content = trim( $content );
		$output  = $content ? "\n\n" . $content . "\n" : '';
		if ( $caption ) {
			$output .= '_' . $caption . '_';
		}
		return $output ? $output . "\n\n" : '';
	}

	/** Render definition list. */
	private static function render_definition_list( DOMNode $dl, $base_url ) {
		$output = '';
		foreach ( $dl->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			$name = strtolower( $child->nodeName );
			if ( 'dt' === $name ) {
				$text = trim( self::render_inline_children( $child, $base_url ) );
				if ( $text ) {
					$output .= "\n\n**" . $text . "**\n";
				}
			} elseif ( 'dd' === $name ) {
				$text = trim( self::render_children( $child, $base_url ) );
				if ( $text ) {
					$output .= $text . "\n";
				}
			}
		}
		return $output ? $output . "\n" : '';
	}

	/** Make relative URL absolute. */
	private static function absolute_url( $url, $base_url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//|#)#i', $url ) ) {
			if ( 0 === strpos( $url, '//' ) ) {
				$scheme = wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );
				return ( $scheme ? $scheme : 'https' ) . ':' . $url;
			}
			return $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			$home = wp_parse_url( home_url( '/' ) );
			$base = ( isset( $home['scheme'] ) ? $home['scheme'] : 'https' ) . '://' . ( isset( $home['host'] ) ? $home['host'] : '' );
			if ( ! empty( $home['port'] ) ) {
				$base .= ':' . (int) $home['port'];
			}
			return $base . $url;
		}
		$base_url = $base_url ? $base_url : home_url( '/' );
		return trailingslashit( $base_url ) . ltrim( $url, '/' );
	}

	/** Escape Markdown link text. */
	private static function escape_link_text( $text ) {
		return str_replace( array( '\\', '[', ']' ), array( '\\\\', '\\[', '\\]' ), (string) $text );
	}

	/** Escape Markdown destination. */
	private static function escape_link_destination( $url ) {
		return str_replace( array( ' ', '(', ')' ), array( '%20', '\\(', '\\)' ), (string) $url );
	}
}
