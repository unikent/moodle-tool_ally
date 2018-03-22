<?php
// This file is part of Moodle - http://moodle.org/
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
 * Push file updates.
 *
 * @package   tool_ally
 * @copyright Copyright (c) 2016 Blackboard Inc. (http://www.blackboard.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ally;

use tool_ally\event\push_file_updates_error;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir.'/filelib.php');

/**
 * Push file updates.
 *
 * @package   tool_ally
 * @copyright Copyright (c) 2016 Blackboard Inc. (http://www.blackboard.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class push_file_updates {
    /**
     * @var push_config
     */
    private $config;

    public function __construct(push_config $config = null) {
        $this->config = $config ?: new push_config();
    }

    /**
     * Post the file updates to the endpoint.
     *
     * @param array $payload The data to send.
     * @param \curl|null $curl Don't pass this in unless its for testing.  Don't re-use curl class between requests.
     * @param int $retrycount
     */
    public function send(array $payload, \curl $curl = null, $retrycount = 0) {
        $content = json_encode(['key' => $this->config->get_key(), 'data' => $payload]);

        $curl = $curl ?: new \curl(['debug' => $this->config->get_debug()]);
        $curl->setHeader('Authorization: Bearer '.$this->config->get_secret());
        $curl->setHeader('Content-Type: application/json');
        $curl->setHeader('Content-Length: '.strlen($content));
        try {
            $curl->post($this->config->get_url(), $content, [
                'CURLOPT_SSL_VERIFYPEER' => 1,
                'CURLOPT_SSL_VERIFYHOST' => 2,
                'CURLOPT_TIMEOUT'        => $this->config->get_timeout(),
                'CURLOPT_CONNECTTIMEOUT' => $this->config->get_connect_timeout(),
            ]);

            $this->verify_error($curl);
            $this->verify_http_code($curl);
        } catch (\Exception $e) {
            if ($retrycount < $this->config->get_max_push_attempts()) {
                usleep(rand(100000, 500000)); // Sleep between 0.1 and 0.5 second.
                $this->send($payload, null, $retrycount + 1);
            } else {
                // Too many errors, ensure it only runs on cli.
                set_config('push_cli_only', 1, 'tool_ally');
                // Log exception after max attempts.
                push_file_updates_error::create_from_exception($e)->trigger();
                // Log live push skip due to errors and switch to cli only.
                push_file_updates_error::create_from_msg(get_string('pushfileserror:skip', 'tool_ally'))->trigger();
            }
        }
    }

    /**
     * Throw exception if there was an error with the request.
     *
     * @param \curl $curl
     * @throws \moodle_exception
     */
    private function verify_error(\curl $curl) {
        if (empty($curl->errno)) {
            return; // No request error.
        }
        $error = $curl->errno;
        if (!empty($curl->error)) {
            $error .= ' - '.$curl->error;
        }
        throw new \moodle_exception('curlerror', 'tool_ally', '', $error);
    }

    /**
     * Throw an exception if the HTTP status code is not 200.
     *
     * @param \curl $curl
     * @throws \moodle_exception
     */
    private function verify_http_code(\curl $curl) {
        /** @var array $info */
        $info = $curl->get_info();
        if (empty($info) || !array_key_exists('http_code', $info)) {
            throw new \moodle_exception('curlnohttpcode', 'tool_ally');
        }
        if ($info['http_code'] !== 200) {
            throw new \moodle_exception('curlinvalidhttpcode', 'tool_ally', '', $info['http_code']);
        }
    }
}
