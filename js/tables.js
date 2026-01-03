var predefined_lengths = null;
var sizesLength = false;

function checkLengths(sValue, idx) {
	if (predefined_lengths) {
		if (sizesLength == false) {
			sizesLength = predefined_lengths.length;
		}
		for (var i = 0; i < sizesLength; i++) {
			if (
				sValue.toString().toUpperCase() ==
				predefined_lengths[i].toString().toUpperCase()
			) {
				document.getElementById("lengths" + idx).value = "";
				document.getElementById("lengths" + idx).disabled = "on";
				return;
			}
		}
		document.getElementById("lengths" + idx).disabled = "";
	}
}

function addColumnRow() {
	var table = document.getElementById("columnsTable");
	var numColumnsInput = document.getElementById("num_columns");
	var currentRowCount = parseInt(numColumnsInput.value);
	var newRowIndex = currentRowCount;

	// Clone the last data row
	var lastRow = table.querySelector("tr[data-row-index]:last-of-type");
	if (!lastRow) {
		return; // No rows to clone
	}

	var newRow = lastRow.cloneNode(true);

	// Update row index attribute
	newRow.setAttribute("data-row-index", newRowIndex);

	// Update alternating row class
	if (newRowIndex % 2 == 0) {
		newRow.className = "data1";
	} else {
		newRow.className = "data2";
	}

	// Update all input elements in the cloned row
	var inputs = newRow.querySelectorAll("input, select");
	for (var i = 0; i < inputs.length; i++) {
		var input = inputs[i];
		var name = input.getAttribute("name");
		var id = input.getAttribute("id");

		// Update array-based names: field[0] -> field[1], etc.
		if (name) {
			var nameMatch = name.match(/^(.+)\[\d+\]$/);
			if (nameMatch) {
				input.setAttribute(
					"name",
					nameMatch[1] + "[" + newRowIndex + "]"
				);
			}
		}

		// Update IDs: types0 -> types1, lengths0 -> lengths1
		if (id) {
			var idMatch = id.match(/^(.+?)(\d+)$/);
			if (idMatch) {
				input.setAttribute("id", idMatch[1] + newRowIndex);
			}
		}

		// Clear values
		if (input.type === "checkbox") {
			input.checked = false;
		} else if (input.tagName === "SELECT") {
			input.selectedIndex = 0;
		} else {
			input.value = "";
		}

		// Update onchange for type select
		if (input.tagName === "SELECT" && name && name.match(/^type\[/)) {
			input.setAttribute(
				"onchange",
				"checkLengths(this.value, " + newRowIndex + ");"
			);
		}
	}

	// Append the new row to the table
	table.appendChild(newRow);

	// Update the hidden num_columns field
	numColumnsInput.value = newRowIndex + 1;

	// Initialize the new row's length checking
	var typeSelect = newRow.querySelector("select[name^='type']");
	if (typeSelect) {
		checkLengths(typeSelect.value, newRowIndex);
	}
}
