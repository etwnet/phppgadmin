// Lightweight loader — dynamically import the new ES module implementation
(async function () {
	if (typeof window === "undefined") return;
	try {
		const mod = await import("./import/index.js");
		mod.init();
	} catch (e) {
		console.error("Failed to import import module:", e);
	}
})();
