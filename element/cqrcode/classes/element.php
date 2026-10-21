<?php
// This file is part of the customcert module for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The Certificate Manager QR code element: a QR code with an optional custom verification URL.
 *
 * @package    customcertelement_cqrcode
 * @copyright  2025 Certificate Manager
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace customcertelement_cqrcode;

use context;
use mod_customcert\element as base_element;
use mod_customcert\element\persistable_element_interface;
use mod_customcert\element\renderable_element_interface;
use mod_customcert\element\form_element_interface;
use mod_customcert\element\validatable_element_interface;
use mod_customcert\element\preparable_form_interface;
use mod_customcert\element_helper;
use mod_customcert\service\element_renderer;
use MoodleQuickForm;
use moodle_url;
use pdf;
use stdClass;
use TCPDF2DBarcode;
use Throwable;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tcpdf/tcpdf_barcodes_2d.php');

/**
 * The customcert element QR code's core interaction API.
 *
 * Stored data is JSON: {"width": int, "height": int, "customverificationurl": string}.
 * This is the same shape the pre-5.x version of this element stored, so existing templates keep working.
 *
 * @package    customcertelement_cqrcode
 * @copyright  2025 Certificate Manager
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class element extends base_element implements
    form_element_interface,
    persistable_element_interface,
    preparable_form_interface,
    renderable_element_interface,
    validatable_element_interface
{
    /**
     * @var string The barcode type.
     */
    public const string BARCODETYPE = 'QRCODE';

    /**
     * Placeholder code used in previews and the drag-and-drop editor.
     */
    private const PREVIEWCODE = 'PREVIEW123456';

    /**
     * Build the configuration form for this element.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public function build_form(MoodleQuickForm $mform): void {
        element_helper::render_form_element_width($mform);
        element_helper::render_form_element_height($mform);

        $mform->addElement(
            'text',
            'customverificationurl',
            get_string('customverificationurl', 'customcertelement_cqrcode'),
            ['size' => 60]
        );
        // Raw: PARAM_URL cleaning would strip the {code} placeholder; the value is validated in validate().
        $mform->setType('customverificationurl', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('customverificationurl', 'customverificationurl', 'customcertelement_cqrcode');

        if (get_config('customcert', 'showposxy')) {
            element_helper::render_form_element_position($mform);
        }
    }

    /**
     * Normalise QR code element data.
     *
     * @param stdClass $formdata Form submission data
     * @return array JSON-serialisable payload
     */
    public function normalise_data(stdClass $formdata): array {
        return [
            'width' => isset($formdata->width) ? (int)$formdata->width : 0,
            'height' => isset($formdata->height) ? (int)$formdata->height : 0,
            'customverificationurl' => self::is_valid_verification_url((string)($formdata->customverificationurl ?? ''))
                ? trim((string)$formdata->customverificationurl)
                : '',
        ];
    }

    /**
     * Prepare the form by populating the fields from stored data.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public function prepare_form(MoodleQuickForm $mform): void {
        $payload = $this->get_payload();
        if (isset($payload['width'])) {
            $mform->setDefault('width', (int)$payload['width']);
        }
        if (isset($payload['height'])) {
            $mform->setDefault('height', (int)$payload['height']);
        }
        if (!empty($payload['customverificationurl'])) {
            $mform->setDefault('customverificationurl', (string)$payload['customverificationurl']);
        }
    }

    /**
     * Handles rendering the element on the pdf.
     *
     * @param pdf $pdf the pdf object
     * @param bool $preview true if it is a preview, false otherwise
     * @param stdClass $user the user we are rendering this for
     * @param element_renderer|null $renderer the renderer service
     */
    public function render(pdf $pdf, bool $preview, stdClass $user, ?element_renderer $renderer = null): void {
        global $DB;

        // If there is no element data, we have nothing to display.
        if (empty($this->get_data())) {
            return;
        }

        $payload = $this->get_payload();
        $customurl = (string)($payload['customverificationurl'] ?? '');

        if ($preview) {
            if ($customurl !== '') {
                $qrcodeurl = str_replace('{code}', self::PREVIEWCODE, $customurl);
            } else {
                $qrcodeurl = (new moodle_url('/'))->out(false);
            }
        } else {
            // Get the information we need.
            $sql = "SELECT c.id, c.verifyany, ct.contextid, ci.code
                      FROM {customcert_issues} ci
                      JOIN {customcert} c
                        ON ci.customcertid = c.id
                      JOIN {customcert_templates} ct
                        ON c.templateid = ct.id
                      JOIN {customcert_pages} cp
                        ON cp.templateid = ct.id
                     WHERE ci.userid = :userid
                       AND cp.id = :pageid";

            // Now we can get the issue for this user.
            $issue = $DB->get_record_sql(
                $sql,
                ['userid' => $user->id, 'pageid' => $this->get_pageid()],
                '*',
                MUST_EXIST
            );
            $code = $issue->code;

            if ($customurl !== '') {
                // Use the custom verification URL and replace the {code} placeholder.
                $qrcodeurl = str_replace('{code}', $code, $customurl);
            } else {
                $context = context::instance_by_id($issue->contextid);

                $urlparams = [
                    'code' => $code,
                    'qrcode' => 1,
                ];

                // We only add the 'contextid' to the link if the site setting for verifying all certificates is off,
                // or if the individual certificate doesn't allow verification. However, if the user has the
                // mod/customcert:verifyallcertificates then they can verify anything regardless.
                $verifyallcertificatessitesetting = get_config('customcert', 'verifyallcertificates');
                $verifycertificateactivitysettings = $issue->verifyany;
                $canverifyallcertificates = has_capability('mod/customcert:verifyallcertificates', $context);
                if (
                    (!$verifyallcertificatessitesetting || !$verifycertificateactivitysettings)
                        && !$canverifyallcertificates
                ) {
                    $urlparams['contextid'] = $issue->contextid;
                }

                // Default verification goes through the Certificate Manager plugin's verification page.
                $qrcodeurl = (new moodle_url('/local/certmanager/verify_certificate.php', $urlparams))->out(false);
            }
        }

        try {
            $barcode = new TCPDF2DBarcode($qrcodeurl, self::BARCODETYPE);
            $image = $barcode->getBarcodePngData((int)$payload['width'], (int)$payload['height']);

            $location = make_request_directory() . '/target';
            file_put_contents($location, $image);

            $pdf->Image($location, $this->get_posx(), $this->get_posy(), (int)$payload['width'], (int)$payload['height']);
        } catch (Throwable $e) {
            if (!defined('PHPUNIT_TEST') && !defined('BEHAT_SITE_RUNNING')) {
                debugging('QR code render failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }

            return;
        }
    }

    /**
     * Render the element in html.
     *
     * This function is used to render the element when we are using the
     * drag and drop interface to position it.
     *
     * @param element_renderer|null $renderer the renderer service
     * @return string the html
     */
    public function render_html(?element_renderer $renderer = null): string {
        // If there is no element data, we have nothing to display.
        if (empty($this->get_data())) {
            return '';
        }

        $payload = $this->get_payload();
        $customurl = (string)($payload['customverificationurl'] ?? '');

        if ($customurl !== '') {
            $qrcodeurl = str_replace('{code}', self::PREVIEWCODE, $customurl);
        } else {
            $qrcodeurl = (new moodle_url('/'))->out(false);
        }

        try {
            $barcode = new TCPDF2DBarcode($qrcodeurl, self::BARCODETYPE);
            $content = $barcode->getBarcodeHTML(((int)$payload['width']) / 10, ((int)$payload['height']) / 10);

            if ($renderer) {
                return (string) $renderer->render_content($this, $content);
            }

            return $content;
        } catch (Throwable $e) {
            if (!defined('PHPUNIT_TEST') && !defined('BEHAT_SITE_RUNNING')) {
                debugging('QR code render failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }

            return '';
        }
    }

    /**
     * Validate submitted form data for this element.
     * Core validations are handled by validation_service; this adds the custom URL check.
     *
     * @param array $data
     * @return array<string,string>
     */
    public function validate(array $data): array {
        $errors = [];

        $url = trim((string)($data['customverificationurl'] ?? ''));
        if ($url !== '' && !self::is_valid_verification_url($url)) {
            $errors['customverificationurl'] = get_string('invalidurl', 'customcertelement_cqrcode');
        }

        return $errors;
    }

    /**
     * Whether a custom verification URL is acceptable: empty, or an http(s) URL once {code} is substituted.
     *
     * @param string $url
     * @return bool
     */
    private static function is_valid_verification_url(string $url): bool {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        $probe = str_replace('{code}', self::PREVIEWCODE, $url);
        return filter_var($probe, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string)parse_url($probe, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
