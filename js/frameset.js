(function() {

	const isRtl = document.documentElement.getAttribute('dir') === "rtl";
	const tree = document.getElementById('tree');
	const content = document.getElementById('content');

	// Frameset simulation
	const resizer = document.getElementById('resizer');

	let isResizing = false;

	resizer.addEventListener('mousedown', e => {
		e.preventDefault();
		isResizing = true;
		document.body.style.cursor = 'col-resize';
	});

	document.addEventListener('mousemove', e => {
		if (isResizing) {
			const newWidth = (isRtl ? window.innerWidth - e.clientX : e.clientX) - resizer.offsetWidth;
			tree.style.width = newWidth + 'px';
		}
	});

	document.addEventListener('mouseup', e => {
		if (isResizing) {
			isResizing = false;
			document.body.style.cursor = 'default';
		}
	});

	// Link and Form interception

	function setContent(html) {
		content.innerHTML = html;
		// Bringing scripts to life
		content.querySelectorAll("script").forEach(oldScript => {
			const newScript = document.createElement("script");
			if (oldScript.src) {
				newScript.src = oldScript.src;
			} else {
				newScript.textContent = oldScript.textContent;
			}
			content.appendChild(newScript);
			oldScript.remove();
		});
	}

	async function loadContent(url, options = {}, addToHistory = true) {
		url = url.replace(/[&?]$/, '') + (url.indexOf('?') >= 0 ? '&' : '?') + 'target=content';
		console.log("Fetching:", url, options);

		let finalUrl = null;

		try {
			const res = await fetch(url, options);

			if (!res.ok) {
				// noinspection ExceptionCaughtLocallyJS
				throw new Error(`HTTP error ${res.status}`);
			}

			const html = await res.text();

			setContent(html);

			const urlObj = new URL(res.url || url, window.location.href);
			urlObj.searchParams.delete('target');
			finalUrl = urlObj.toString();

			if (addToHistory) {
				const data = {};
				if (/post/i.test(options.method ?? '')) {
					data.html = html;
				}
				history.pushState(data, document.title, finalUrl);
				// Scroll back to the top
				content.scrollTo(0, 0);
			}

			const event = new CustomEvent("frameLoaded", {
				detail: { url: finalUrl },
				target: content,
			});
			document.dispatchEvent(event);

		} catch (err) {
			console.error("Error:", err);
			window.alert(err);
		}
	}

	let lastSubmitButton = null;

	document.addEventListener("click", e => {

		if (e.target.matches("input[type=submit], button[type=submit]")) {
			lastSubmitButton = e.target;
			return;
		}
		lastSubmitButton = null;

		const target = e.target.closest('a');
		if (!target) {
			return;
		}

		e.preventDefault();
		e.stopPropagation();

		if (target.href === window.location.href + '#') {
			// Emulate scroll top
			if (target.classList.contains("bottom_link")) {
				content.scrollTo({
					top: 0,
					left: 0,
					behavior: "smooth"
				});
			}
			return;
		}

		if (target.href.startsWith("javascript")) {
			return;
		}

		console.log('Intercepted link:', target.href);
		return loadContent(target.href);
	});

	document.addEventListener('submit', e => {
		//console.log("Check:", e);

		const form = e.target;
		if (!form.matches('form')) return;

		e.preventDefault();
		console.log("Intercepted form:", form);

		const action = form.getAttribute('action');
		const method = form.getAttribute('method') || 'GET';
		const post = /post/i.test(method);

		const formData = new FormData(form);

		const submitter = e.submitter || lastSubmitButton;
		if (submitter && submitter.name) {
			formData.append(submitter.name, submitter.value);
		}
		lastSubmitButton = null;

		const url = new URL(action, window.location.href);
		const params = new URLSearchParams(url.search);

		if (post) {
			// add hidden input fields to search query
			const hiddenInputs = form.querySelectorAll("input[type=hidden]");
			hiddenInputs.forEach(input => {
				if (input.name) {
					if (!/^(loginServer|action)$/.test(input.name)) {
						params.append(input.name, input.value);
					}
					//formData.delete(input.name);
				}
			});
		} else {
			// add complete form to search query
			for (const [key, value] of formData.entries()) {
				params.append(key, value);
			}
		}

		url.search = params.toString();

		if (post) {
			return loadContent(url.toString(), {
				method: method,
				body: formData
			});
		} else {
			return loadContent(url.toString());
		}
	});

	window.addEventListener('popstate', e => {
		if (e.state.html) {
			setContent(e.state.html);
			return;
		}
		const url = window.location.href;
		return loadContent(url, {}, false);
	});


})();
