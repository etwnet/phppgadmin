(function() {

	window.toggleAllMf = function (bool) {

		var inputs = document
			.getElementById('multi_form')
			.getElementsByTagName('input');

		for (var i = 0; i < inputs.length; i++) {
			if (inputs[i].type == 'checkbox')
				inputs[i].checked = bool;
		}
		return false;
	}

	/**
	 * @param {HTMLElement} element
	 * @param {Object} options
	 */
	function createDateTimePickerInternal(element, options) {
		const originalValue = element.value;
		element.value = "";

		const sharedOptions = {
			clickOpens: false,
			allowInput: true,
			defaultDate: element.value || null,

			onChange: (selectedDates, dateStr, instance) => {
				const cbExpr = document.getElementById('cb_expr_' + element.dataset.field);
				if (cbExpr) cbExpr.checked = false;
				const cbNull = document.getElementById('cb_null_' + element.dataset.field);
				if (cbNull) cbNull.checked = false;
				const selFnc = document.getElementById('sel_fnc_' + element.dataset.field);
				if (selFnc) selFnc.value = "";
			},

			onReady: (selectedDates, dateStr, instance) => {
				element.value = originalValue;
			},
		};

		options = { ...options, ...sharedOptions };

		const fp = flatpickr(element, options);

		// Create wrapper container
		const container = document.createElement("div");
		container.classList.add("date-picker-input-container");

		// Create button
		const button = document.createElement("div");
		button.className = "date-picker-button";
		button.innerHTML = "📅";

		element.parentNode.insertBefore(container, element);

		// Move input into container
		container.appendChild(element);
		container.appendChild(button);

		button.addEventListener("click", () => {
			// Make input readonly while picker is open
			element.readOnly = true;
			fp.open();
			fp.config.onClose.push(() => {
				element.readOnly = false;
			});
		});

		element.addEventListener("click", () => fp.close());
	}

	/**
	 * Format: [+-]0001-12-11[ BC]
	 * @param {HTMLElement} element
	 */
	window.createDatePicker = function (element) {
		const options = {
			dateFormat: "Y-m-d",

			parseDate: (datestr, format) => {
				element.dataset.date = datestr;
				const clean = datestr
					.replace(/^[-+]\d{4}/, match => match.slice(1)) // strip sign from year
					.replace(/\s?(BC|AD)$/i, "");                   // strip era
				return flatpickr.parseDate(clean, format) ?? new Date();
			},

			formatDate: (date, format, locale) => {
				const prevDateStr = element.dataset.date ?? "";
				let datestr = flatpickr.formatDate(date, format, locale);

				const prefixMatch = prevDateStr.match(/^[-+]/);
				if (prefixMatch) {
					datestr = prefixMatch[0] + datestr;
				}

				const match = prevDateStr.match(/\s?(BC|AD)$/i);
				if (match) {
					datestr += match[0];
				}

				return datestr;
			},
		};

		createDateTimePickerInternal(element, options);
	}

	/**
	 * Format: [+-]0001-12-11 19:35:00[+02][ BC]
	 * @param {HTMLElement} element
	 */
	window.createDateTimePicker = function (element) {

		const options = {
			enableTime: true,
			enableSeconds: true,
			time_24hr: true,
			dateFormat: "Y-m-d H:i:S",
			minuteIncrement: 1,
			defaultHour: 0,

			parseDate: (datestr, format) => {
				//console.log(datestr);
				// Save original string for later reconstruction
				element.dataset.date = datestr;

				// Strip sign from year, timezone, and BC/AD suffix
				const clean = datestr
					.replace(/^([-+])(\d{4})/, "$2")          // remove leading +/-
					.replace(/([+-]\d{2}:?\d{2}|Z)?\s?(BC|AD)?$/i, ""); // remove tz + era

				return flatpickr.parseDate(clean.trim(), format) ?? new Date();
			},

			formatDate: (date, format, locale) => {
				const prevDateStr = element.dataset.date ?? "";
				//console.log(prevDateStr);
				//console.log(new Error());
				let datestr = flatpickr.formatDate(date, format, locale);

				// Reattach sign if original year had one
				const prefixMatch = prevDateStr.match(/^[-+]/);
				if (prefixMatch) {
					datestr = prefixMatch[0] + datestr;
				}

				// Reattach timezone and/or BC/AD suffix if present
				const match = prevDateStr.match(/([+-]\d{2}(:?\d{2})?|Z)?(\s?(BC|AD))?$/);
				if (match && match[1]) {
					datestr += match[1];
				}
				if (match && match[2]) {
					datestr += match[1];
				}

				return datestr;
			},
		};

		createDateTimePickerInternal(element, options);
	}

	/**
	 * @param {HTMLElement} element
	 */
	window.createSqlEditor = function (element) {
		if (element.classList.contains("ace_editor")) {
			// Editor already created
			return;
		}
		const editorDiv = document.createElement("div");
		editorDiv.className = element.className;
		//editorDiv.style.width = textarea.style.width || "100%";
		//editorDiv.style.height = textarea.style.height || "100px";

		const hidden = document.createElement("input");
		hidden.type = "hidden";
		hidden.name = element.name;

		element.insertAdjacentElement("afterend", editorDiv);
		editorDiv.insertAdjacentElement("afterend", hidden);
		element.remove();

		const editor = ace.edit(editorDiv);
		editor.setShowPrintMargin(false);
		editor.session.setUseWrapMode(true);
		editor.session.setMode("ace/mode/pgsql");
		editor.setHighlightActiveLine(false);
		editor.renderer.$cursorLayer.element.style.display = "none";
		editor.setValue(element.value || "", -1);

		editor.session.on("change", function() {
			hidden.value = editor.getValue();
		});

		editor.on("blur", () => {
			editor.setHighlightActiveLine(false);
			editor.renderer.$cursorLayer.element.style.display = "none";
		});

		editor.on("focus", () => {
			editor.setHighlightActiveLine(true);
			editor.renderer.$cursorLayer.element.style.display = "";
		});

		hidden.value = editor.getValue();
	}

	/**
	 * @param {HTMLElement} element
	 */
	window.createSqlViewer = function (element) {
		if (element.classList.contains("ace_editor")) {
			// Editor already created
			return;
		}
		const editor = ace.edit(element);
		editor.session.setUseWrapMode(true);
		editor.session.setMode("ace/mode/pgsql");
		editor.setReadOnly(true);
		editor.renderer.$cursorLayer.element.style.display = "none";
		editor.renderer.setShowGutter(false);
		editor.setHighlightActiveLine(false);
		editor.setShowPrintMargin(false);
		editor.setOptions({
			maxLines: Infinity,
			highlightGutterLine: false,
			showLineNumbers: true,
		});

		editor.on("blur", function() {
			editor.clearSelection();
		});
	}

	/**
	 *
	 * @param {HTMLElement} rootElement
	 */
	function createSqlEditors(rootElement) {
		rootElement.querySelectorAll(".sql-editor").forEach(element => {
			//console.log(element);
			createSqlEditor(element);
		});

		rootElement.querySelectorAll(".sql-viewer").forEach(element => {
			createSqlViewer(element);
		});
	}

	/**
	 *
	 * @param {HTMLElement} rootElement
	 */
	function createDateAndTimePickers(rootElement) {
		rootElement.querySelectorAll("input[data-type^=timestamp]").forEach(element => {
			//console.log(element);
			createDateTimePicker(element);
		});
		rootElement.querySelectorAll("input[data-type^=date]").forEach(element => {
			//console.log(element);
			createDatePicker(element);
		});
	}

	// Tooltips

	const tooltip = document.getElementById('tooltip');
	const tooltipContent = document.getElementById('tooltip-content');
	let popperInstance = null;

	window.showTooltip = function(referenceEl, text) {
		console.log("show tooltip", referenceEl);
		text = text || referenceEl.dataset.desc || "Description missing!";
		if (!/<\w+/.test(text)) {
			// plain text, convert line endings into html breaks
			text = text.replace(/\n/g, '<br>\n');
		}
		tooltipContent.innerHTML = text;
		tooltip.style.display = 'block';

		if (popperInstance) {
			popperInstance.destroy();
		}

		popperInstance = Popper.createPopper(referenceEl, tooltip, {
			placement: 'top',
		});
	}

	window.hideTooltip = function () {
		tooltip.style.display = 'none';
		if (popperInstance) {
			popperInstance.destroy();
			popperInstance = null;
		}
	}

	// Virtual Frame Event

	document.addEventListener("frameLoaded", function(e) {
		console.log("Frame loaded:", e.detail.url);
		createSqlEditors(e.target);
		createDateAndTimePickers(e.target);
	});

	// Initialization

	flatpickr.localize(flatpickr.l10ns.default);
	createSqlEditors(document.documentElement);
	createDateAndTimePickers(document.documentElement);

	const acForm = document.getElementById("ac_form");
	if (acForm) {

	}

})();
