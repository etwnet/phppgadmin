import { el } from "./utils.js";

// Server parameter helpers
export const SERVER_ID = (function () {
	const inp = el("import_server");
	if (inp && inp.value) return inp.value;
	const ui = el("importUI");
	if (ui && ui.dataset && ui.dataset.server) return ui.dataset.server;
	return "";
})();

export function appendServerToUrl(url) {
	if (!SERVER_ID) return url;
	return (
		url +
		(url.indexOf("?") === -1 ? "?" : "&") +
		"server=" +
		encodeURIComponent(SERVER_ID)
	);
}

export function appendServerToParams(params) {
	if (!SERVER_ID) return params;
	try {
		if (params instanceof URLSearchParams || params instanceof FormData) {
			params.append("server", SERVER_ID);
			return params;
		}
	} catch (e) {
		// ignore
	}
	if (typeof params === "string") {
		return params + "&server=" + encodeURIComponent(SERVER_ID);
	}
	return params;
}
