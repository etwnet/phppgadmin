/** Frameset handler simulating frameset behavior */

function frameSetHandler() {
	const isRtl = document.documentElement.getAttribute("dir") === "rtl";
	const tree = document.getElementById("tree");
	const content = document.getElementById("content");
	const contentContainer = document.getElementById("content-container");

	// Helper function to escape HTML special characters
	const escapeHtml = (text) => {
		const map = {
			"&": "&amp;",
			"<": "&lt;",
			">": "&gt;",
			'"': "&quot;",
			"'": "&#039;",
		};
		return text.replace(/[&<>"']/g, (m) => map[m]);
	};

	// Frameset simulation
	const resizer = document.getElementById("resizer");
	if (!resizer) {
		console.warn("No resizer element found!");
		return false;
	}

	let isResizing = false;

	resizer.addEventListener("mousedown", (e) => {
		e.preventDefault();
		isResizing = true;
		document.body.style.cursor = "col-resize";
	});

	document.addEventListener("mousemove", (e) => {
		if (isResizing) {
			const newWidth =
				(isRtl ? window.innerWidth - e.clientX : e.clientX) -
				resizer.offsetWidth;
			tree.style.width = newWidth + "px";
			positionLoadingIndicator();
		}
	});

	document.addEventListener("mouseup", (e) => {
		if (isResizing) {
			isResizing = false;
			document.body.style.cursor = "default";
		}
	});

	// Loading indicator

	const loadingIndicator = document.getElementById("loading-indicator");

	function positionLoadingIndicator() {
		const rect = tree.getBoundingClientRect();
		loadingIndicator.style.position = "fixed";
		loadingIndicator.style.width = rect.width + "px";
		//loadingIndicator.style.left = rect.left + "px";
	}

	positionLoadingIndicator();

	window.addEventListener("scroll", positionLoadingIndicator);
	window.addEventListener("resize", positionLoadingIndicator);

	// Link and Form interception

	function setContent(html) {
		content.innerHTML = html;
		// Bringing scripts to life
		content.querySelectorAll("script").forEach((oldScript) => {
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
		url = url.replace(/[&?]$/, "");
		url += (url.includes("?") ? "&" : "?") + "target=content";
		console.log("Fetching:", url, options);

		// Check if this is a download request (gzipped or download output)
		const urlObj = new URL(url, window.location.href);
		const output = urlObj.searchParams.get("output");
		if (output === "download" || output === "gzipped") {
			// For actual file downloads, open in new window and let browser handle it
			window.open(url, "_blank");
			return;
		}

		let finalUrl = null;
		let indicatorTimeout = window.setTimeout(() => {
			loadingIndicator.classList.add("show");
		}, 200);

		try {
			document.body.style.cursor = "wait";
			const res = await fetch(url, options);

			if (!res.ok) {
				// noinspection ExceptionCaughtLocallyJS
				throw new Error(`HTTP error ${res.status}`);
			}

			const contentType = res.headers.get("content-type") || "";
			const responseText = await res.text();

			const unloadEvent = new CustomEvent("beforeFrameUnload", {
				target: content,
			});
			document.dispatchEvent(unloadEvent);

			if (content.querySelector("form")) {
				// If there was a form, replace current history state
				const data = {
					html: content.innerHTML,
				};
				history.replaceState(data, document.title, location.href);
				//console.log("Replaced history state for form content");
			}

			if (
				contentType.includes("text/plain") ||
				contentType.includes("application/download")
			) {
				// Handle different content types
				// For plain text (dumps/exports), wrap in <pre> tag
				setContent(`<pre>${escapeHtml(responseText)}</pre>`);
			} else {
				// For HTML, parse as normal
				setContent(responseText);
			}

			const urlObj = new URL(res.url || url, window.location.href);
			urlObj.searchParams.delete("target");
			finalUrl = urlObj.toString();

			if (addToHistory) {
				const form = content.querySelector("form");
				const data = {};
				if (/post/i.test(options.method ?? "") || form) {
					data.html = responseText;
				}
				history.pushState(data, document.title, finalUrl);
				// Scroll back to the top
				contentContainer.scrollTo(0, 0);
			}

			const loadedEvent = new CustomEvent("frameLoaded", {
				detail: { url: finalUrl },
				target: content,
			});
			document.dispatchEvent(loadedEvent);
		} catch (err) {
			console.error("Error:", err);
			window.alert(err);
		} finally {
			document.body.style.cursor = "default";
			loadingIndicator.classList.remove("show");
			window.clearTimeout(indicatorTimeout);
		}
	}

	let lastSubmitButton = null;

	document.addEventListener("click", (e) => {
		if (e.target.matches("input[type=submit], button[type=submit]")) {
			lastSubmitButton = e.target;
			return;
		}
		lastSubmitButton = null;

		const target = e.target.closest("a");
		if (!target) {
			return;
		}

		const url = new URL(target.href, window.location.origin);
		if (target.target || url.host !== window.location.host) {
			// Ignore external links
			return;
		}

		e.preventDefault();
		e.stopPropagation();

		if (target.href === window.location.href + "#") {
			// Emulate scroll top
			if (target.classList.contains("bottom_link")) {
				contentContainer.scrollTo({
					top: 0,
					left: 0,
					behavior: "smooth",
				});
			}
			return;
		}

		if (target.href.startsWith("javascript")) {
			return;
		}

		console.log("Intercepted link:", target.href);
		return loadContent(target.href);
	});

	document.addEventListener("submit", (e) => {
		//console.log("Check:", e);

		const form = e.target;
		if (!form.matches("form")) return;

		e.preventDefault();
		console.log("Intercepted form:", form);

		const action = form.getAttribute("action");
		const method = form.getAttribute("method") || "GET";
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
			hiddenInputs.forEach((input) => {
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
				body: formData,
			});
		} else {
			return loadContent(url.toString());
		}
	});

	window.addEventListener("popstate", (e) => {
		const url = window.location.href;

		if (e.state?.html) {
			setContent(e.state.html);
			const event = new CustomEvent("frameLoaded", {
				detail: { url: url },
				target: content,
			});
			document.dispatchEvent(event);
		} else {
			loadContent(url, {}, false);
		}
	});

	window.addEventListener("message", (event) => {
		console.log("Received message:", event.data);
		if (event.origin !== window.location.origin) {
			console.warn(
				"Origin mismatch:",
				event.origin,
				window.location.origin
			);
			return;
		}
		const { type, payload } = event.data;
		if (type === "formSubmission") {
			//loadContent(payload.url);
			if (payload.post) {
				const formData = new FormData();
				for (const [key, value] of Object.entries(payload.data)) {
					formData.append(key, value);
				}
				return loadContent(payload.url, {
					method: payload.method,
					body: formData,
				});
			} else {
				return loadContent(payload.url);
			}
		} else if (type === "linkNavigation") {
			return loadContent(payload.url);
		}
	});

	return true;
}

/** Popup handler intecepting form submissions and links */

function popupHandler() {
	document.addEventListener("submit", (e) => {
		const form = e.target;
		if (!form.matches("form")) return;

		// We lost the popup reference, lets create a new one again
		if (!window.opener) return;

		e.preventDefault();
		console.log("Intercepted form:", form);

		const action = form.getAttribute("action");
		let method = form.getAttribute("method") || "GET";
		const post = /post/i.test(method);

		const formData = new FormData(form);

		const url = new URL(action, window.location.href);
		const params = new URLSearchParams(url.search);

		if (post) {
			// add hidden input fields to search query
			const hiddenInputs = form.querySelectorAll("input[type=hidden]");
			hiddenInputs.forEach((input) => {
				if (input.name) {
					if (!/^(action)$/.test(input.name)) {
						params.append(input.name, input.value);
					}
				}
			});
		} else {
			// add complete form to search query
			for (const [key, value] of formData.entries()) {
				params.append(key, value);
			}
		}

		url.search = params.toString();

		window.opener.postMessage(
			{
				type: "formSubmission",
				payload: {
					method: method,
					post: post,
					url: url.toString(),
					data: Object.fromEntries(formData.entries()),
				},
			},
			window.opener.location.origin
		);
	});

	document.addEventListener("click", (e) => {
		const target = e.target.closest("a");
		if (!target) {
			return;
		}

		const url = new URL(target.href, window.location.origin);
		if (url.host !== window.location.host) {
			// Ignore external links
			return;
		}

		if (target.target != "detail") {
			// Intercept only frameset links
			return;
		}

		// We lost the popup reference, lets create a new one again
		if (!window.opener) return;

		e.preventDefault();
		e.stopPropagation();

		if (target.href === window.location.href + "#") {
			// Emulate scroll top
			if (target.classList.contains("bottom_link")) {
				contentContainer.scrollTo({
					top: 0,
					left: 0,
					behavior: "smooth",
				});
			}
			return;
		}

		if (target.href.startsWith("javascript")) {
			return;
		}

		console.log("Intercepted link:", target.href);

		window.opener.postMessage(
			{
				type: "linkNavigation",
				payload: {
					url: target.href,
				},
			},
			window.opener.location.origin
		);
	});

	return true;
}

(function () {
	// Try to initialize frameset handler, if not possible, fallback to popup handler
	frameSetHandler() || popupHandler();

	const content = document.getElementById("content");

	document.addEventListener("DOMContentLoaded", (e) => {
		// dispatch virtual frame event
		const event = new CustomEvent("frameLoaded", {
			detail: { url: window.location.href },
			target: content,
		});
		document.dispatchEvent(event);
	});
})();
