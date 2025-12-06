function toggleAllMf(bool) {

	var inputs = document
		.getElementById('multi_form')
		.getElementsByTagName('input');

	for (var i = 0; i < inputs.length; i++) {
		if (inputs[i].type == 'checkbox')
			inputs[i].checked = bool;
	}
	return false;
}

(function() {

	/**
	 *
	 * @param {HTMLElement} rootElement
	 */
	function createSqlEditors(rootElement) {
		rootElement.querySelectorAll(".sql-editor").forEach(element => {
			console.log(element);
			if (element.classList.contains("ace_editor")) {
				// Editor already created
				return;
			}
			if (element.matches("textarea")) {
				// special case textarea
				const textarea = element;
				const editorDiv = document.createElement("div");
				editorDiv.className = textarea.className;
				//editorDiv.style.width = textarea.style.width || "100%";
				//editorDiv.style.height = textarea.style.height || "100px";

				const hidden = document.createElement("input");
				hidden.type = "hidden";
				hidden.name = textarea.name;

				textarea.insertAdjacentElement("afterend", editorDiv);
				editorDiv.insertAdjacentElement("afterend", hidden);
				textarea.remove();

				const editor = ace.edit(editorDiv);
				editor.setShowPrintMargin(false);
				editor.session.setUseWrapMode(true);
				editor.session.setMode("ace/mode/pgsql");
				editor.setValue(textarea.value || "", -1);

				editor.session.on("change", function() {
					hidden.value = editor.getValue();
				});

				hidden.value = editor.getValue();
			}
		});
	}

	createSqlEditors(document.documentElement);

	// Virtual Frame Event
	document.addEventListener("frameLoaded", function(e) {
		console.log("Frame loaded:", e.detail.url);
		createSqlEditors(e.target);
	});

})();
