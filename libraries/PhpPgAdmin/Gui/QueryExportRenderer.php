<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Import\CompressionReader;

/**
 * QueryExportRenderer - Renders export form for SQL query results
 * Simplifies format selection for exporting arbitrary query data
 */
class QueryExportRenderer
{
    private $misc;
    private $lang;

    public function __construct()
    {
        $this->misc = AppContainer::getMisc();
        $this->lang = AppContainer::getLang();
    }

    /**
     * Render export form for query result data
     *
     * @param string $query The SQL query to export results from
     * @param array $params Optional parameters (search_path, schema, etc.)
     * @return void
     */
    public function renderExportForm($query, $params = [])
    {
        $compressionCaps = CompressionReader::capabilities();
        ?>
        <form action="dataexport.php" id="export-form" method="get">

            <!-- Export Format Selection (Unified with INSERT options for SQL) -->
            <fieldset id="format_options">
                <legend><?= $this->lang['strexportformat']; ?></legend>

                <!-- Format Selection -->
                <div class="flex-row flex-wrap" id="format_selection">
                    <div class="mx-1">
                        <input type="radio" id="format_sql" name="output_format" value="sql" checked="checked" />
                        <label for="format_sql">SQL</label>
                    </div>
                    <div class="mx-1">
                        <input type="radio" id="format_csv" name="output_format" value="csv" />
                        <label for="format_csv">CSV</label>
                    </div>
                    <div class="mx-1">
                        <input type="radio" id="format_tab" name="output_format" value="tab" />
                        <label for="format_tab">Tab-Delimited</label>
                    </div>
                    <div class="mx-1">
                        <input type="radio" id="format_html" name="output_format" value="html" />
                        <label for="format_html">XHTML</label>
                    </div>
                    <div class="mx-1">
                        <input type="radio" id="format_xml" name="output_format" value="xml" />
                        <label for="format_xml">XML</label>
                    </div>
                    <div class="mx-1">
                        <input type="radio" id="format_json" name="output_format" value="json" />
                        <label for="format_json">JSON</label>
                    </div>
                </div>

                <!-- INSERT Format Options (only shown when SQL format is selected) -->
                <div id="insert_format_options"
                    style="display:block; margin-top: 15px; padding-top: 15px; border-top: 1px solid #ccc;">
                    <p class="small"><strong><?= $this->lang['strinsertformat']; ?></strong>
                        <?= $this->lang['strinsertformat_desc']; ?></p>
                    <div style="margin-left: 20px;">
                        <div>
                            <input type="radio" id="insert_copy" name="insert_format" value="copy" checked="checked" />
                            <label for="insert_copy"><?= $this->lang['strcopyformat']; ?></label>
                        </div>
                        <div>
                            <input type="radio" id="insert_multi" name="insert_format" value="multi" />
                            <label for="insert_multi"><?= $this->lang['strmultirowinserts']; ?></label>
                        </div>
                        <div>
                            <input type="radio" id="insert_single" name="insert_format" value="single" />
                            <label for="insert_single"><?= $this->lang['strsingleinserts']; ?></label>
                        </div>
                    </div>
                </div>
            </fieldset>

            <!-- Output Options -->
            <fieldset>
                <legend><?= $this->lang['stroutput']; ?></legend>
                <div>
                    <input type="radio" id="output_show" name="output" value="show" checked="checked" />
                    <label for="output_show"><?= $this->lang['strshowinbrowser']; ?></label>
                </div>
                <div>
                    <input type="radio" id="output_download" name="output" value="download" />
                    <label for="output_download"><?= $this->lang['strdownloadasfile']; ?></label>
                </div>
                <?php if ($compressionCaps['gzip'] ?? false): ?>
                    <div>
                        <input type="radio" id="output_download_gzip" name="output" value="download-gzip" />
                        <label for="output_download_gzip"><?= $this->lang['strdownloadasgzipped'] ?? 'Download as Gzip'; ?></label>
                    </div>
                <?php endif ?>
                <?php if ($compressionCaps['bzip2'] ?? false): ?>
                    <div>
                        <input type="radio" id="output_download_bzip2" name="output" value="download-bzip2" />
                        <label for="output_download_bzip2"><?= $this->lang['strdownloadasbzip2'] ?? 'Download as Bzip2'; ?></label>
                    </div>
                <?php endif ?>
                <?php if ($compressionCaps['zip'] ?? false): ?>
                    <div>
                        <input type="radio" id="output_download_zip" name="output" value="download-zip" />
                        <label for="output_download_zip"><?= $this->lang['strdownloadaszip'] ?? 'Download as ZIP'; ?></label>
                    </div>
                <?php endif ?>
            </fieldset>

            <p>
                <input type="hidden" name="action" value="export" />
                <input type="hidden" name="query" value="<?= html_esc($query); ?>" />
                <?php foreach ($params as $key => $value): ?>
                    <?php if (!in_array($key, ['action', 'query'])): ?>
                        <input type="hidden" name="<?= html_esc($key); ?>" value="<?= html_esc($value); ?>" />
                    <?php endif; ?>
                <?php endforeach; ?>
                <?= $this->misc->form; ?>
                <input type="submit" value="<?= $this->lang['strexport']; ?>" />
            </p>
        </form>

        <script>
            {
                const form = document.getElementById('export-form');
                const outputFormatRadios = form.querySelectorAll('input[name="output_format"]');
                const insertFormatOptions = document.getElementById('insert_format_options');

                if (outputFormatRadios.length > 0 && insertFormatOptions) {
                    function updateOptions() {
                        const selectedFormat = form.querySelector('input[name="output_format"]:checked').value;
                        const isSqlFormat = selectedFormat === 'sql';

                        // Show/hide INSERT format options only when SQL format is selected
                        if (isSqlFormat) {
                            insertFormatOptions.style.display = 'block';
                        } else {
                            insertFormatOptions.style.display = 'none';
                        }
                    }

                    outputFormatRadios.forEach(radio => radio.addEventListener('change', updateOptions));
                    updateOptions(); // Initial state
                }
            }
        </script>
        <?php
    }
}
