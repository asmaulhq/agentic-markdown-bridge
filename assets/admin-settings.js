(function () {
	'use strict';

	var config = window.AgenticMarkdownAdminSettings || {};
	var strings = config.strings || {};
	var filter = document.getElementById('amb-generator-filter');
	var posts = document.getElementById('amb-generator-posts');
	var generate = document.getElementById('amb-generate-llms');
	var status = document.getElementById('amb-generate-llms-status');
	var summary = document.getElementById('amb-generator-summary');
	var useMarkdown = document.getElementById('amb-generator-use-markdown');
	var editor = document.getElementById('amb-llms-content');
	var preview = document.getElementById('amb-ai-prompt-preview');
	var copy = document.getElementById('amb-copy-ai-prompt');
	var copyStatus = document.getElementById('amb-copy-ai-prompt-status');
	var aiStatus = document.getElementById('amb-ai-provider-status');
	var providerUrls = config.providerUrls || {};

	function buildAiPrompt() {
		var siteSummary = summary && summary.value.trim() ? summary.value.trim() : 'Not provided';
		var lines = [];
		lines.push('Act as a **Technical SEO + GEO/AEO + AI Discoverability Specialist**.');
		lines.push('');
		lines.push('Create or improve a production-ready `llms.txt` for:');
		lines.push('');
		lines.push('Website: ' + (config.homeUrl || ''));
		lines.push('Existing llms.txt: ' + (config.llmsUrl || ''));
		lines.push('Summary: ' + siteSummary);
		lines.push('');
		lines.push('Instructions:');
		lines.push('');
		lines.push('- Inspect the website and existing `llms.txt` first.');
		lines.push('- Use only verified facts and working public URLs.');
		lines.push('- Do not invent claims, services, people, clients, results, or pages.');
		lines.push('- Treat `llms.txt` as a **curated high-value resource map**, not a sitemap.');
		lines.push('- Prioritize the pages that best explain the entity, core offerings, expertise, authoritative work/case studies, key resources, people, and contact.');
		lines.push('- Prefer unique, authoritative pages over overlapping or repetitive ones.');
		lines.push('- Use exactly one H1, an optional short blockquote, and only relevant H2 sections.');
		lines.push('- Format links as:');
		lines.push('  `- [Page Name](URL): Short factual description.`');
		lines.push('- Exclude duplicate, thin, legal, utility, archive, login, search, pagination, and low-value pages.');
		lines.push('- Prefer canonical URLs, or intentional Markdown endpoints specifically provided for AI/LLM consumption.');
		lines.push('- Keep the file concise, scannable, and useful for retrieval.');
		lines.push('');
		lines.push('Before output, verify that every included link adds meaningful information and that no important core page is missing.');
		lines.push('');
		lines.push('Return only the final `llms.txt` Markdown. No code fence, commentary, citations, or explanation.');
		return lines.join('\n');
	}

	function refreshPreview() {
		if (preview) {
			preview.value = buildAiPrompt();
		}
	}

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}

		return new Promise(function (resolve, reject) {
			var textarea = document.createElement('textarea');
			textarea.value = text;
			textarea.setAttribute('readonly', '');
			textarea.style.position = 'fixed';
			textarea.style.opacity = '0';
			document.body.appendChild(textarea);
			textarea.select();
			try {
				if (document.execCommand('copy')) {
					resolve();
				} else {
					reject(new Error('Copy failed'));
				}
			} catch (error) {
				reject(error);
			}
			document.body.removeChild(textarea);
		});
	}

	if (filter && posts) {
		filter.addEventListener('input', function () {
			var query = filter.value.toLowerCase();
			Array.prototype.forEach.call(posts.options, function (option) {
				option.hidden = Boolean(query && option.text.toLowerCase().indexOf(query) === -1);
			});
		});
		posts.addEventListener('change', refreshPreview);
	}

	if (summary) {
		summary.addEventListener('input', refreshPreview);
	}
	if (useMarkdown) {
		useMarkdown.addEventListener('change', refreshPreview);
	}
	refreshPreview();

	if (generate && posts) {
		generate.addEventListener('click', function () {
			var formData = new FormData();
			formData.append('action', 'agentic_mb_generate_llms_preview');
			formData.append('_ajax_nonce', config.nonce || '');
			formData.append('summary', summary ? summary.value : '');
			formData.append('use_markdown', useMarkdown && useMarkdown.checked ? '1' : '0');
			Array.prototype.forEach.call(posts.selectedOptions, function (option) {
				formData.append('ids[]', option.value);
			});

			generate.disabled = true;
			if (status) {
				status.textContent = strings.generating || 'Generating…';
			}

			fetch(config.ajaxUrl || window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			})
				.then(function (response) { return response.json(); })
				.then(function (response) {
					if (!response.success) {
						throw new Error(response.data && response.data.message ? response.data.message : (strings.failed || 'Failed'));
					}
					if (editor) {
						editor.value = response.data.content;
						editor.scrollIntoView({ behavior: 'smooth', block: 'center' });
					}
					if (status) {
						status.textContent = strings.draftGenerated || 'Draft generated. Review it, then Save Changes.';
					}
				})
				.catch(function (error) {
					if (status) {
						status.textContent = error.message;
					}
				})
				.finally(function () {
					generate.disabled = false;
				});
		});
	}

	if (copy) {
		copy.addEventListener('click', function () {
			var prompt = buildAiPrompt();
			refreshPreview();
			copyText(prompt)
				.then(function () {
					if (copyStatus) {
						copyStatus.textContent = strings.promptCopied || 'Prompt copied.';
					}
				})
				.catch(function () {
					if (copyStatus) {
						copyStatus.textContent = strings.copyFailedManual || 'Copy failed. Select the prompt above and copy it manually.';
					}
				});
		});
	}

	Array.prototype.forEach.call(document.querySelectorAll('.amb-ai-provider'), function (button) {
		button.addEventListener('click', function () {
			var provider = button.dataset.provider;
			var prompt = buildAiPrompt();
			var encoded = encodeURIComponent(prompt);
			var url = '';
			refreshPreview();

			if (provider === 'gemini' || provider === 'claude') {
				url = providerUrls[provider] || (provider === 'claude' ? 'https://claude.ai/new' : 'https://gemini.google.com/app');
				window.open(url, '_blank', 'noopener,noreferrer');
				copyText(prompt)
					.then(function () {
						if (aiStatus) {
							aiStatus.textContent = provider === 'claude' ? (strings.claudeCopied || '') : (strings.geminiCopied || '');
						}
					})
					.catch(function () {
						if (aiStatus) {
							aiStatus.textContent = provider === 'claude' ? (strings.claudeManual || '') : (strings.geminiManual || '');
						}
					});
				return;
			}

			url = (providerUrls[provider] || '').replace('{prompt}', encoded);
			if (url) {
				window.open(url, '_blank', 'noopener,noreferrer');
				if (aiStatus) {
					aiStatus.textContent = strings.openedProvider || 'Opened in a new tab. Review the AI output before saving it as llms.txt.';
				}
			}
		});
	});
}());
