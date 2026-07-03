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
 * Test data generator for mod_confscheduler.
 *
 * @package    mod_confscheduler
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Test data generator for mod_confscheduler.
 *
 * No custom behaviour is needed yet; this simply enables
 * $this->getDataGenerator()->create_module('confscheduler', ...) in tests,
 * which delegates to confscheduler_add_instance() via the parent class. Note
 * that confprogramcmid is a required field on confscheduler: tests must pass
 * an existing mod_confprogram course-module id explicitly.
 */
class mod_confscheduler_generator extends testing_module_generator {
}
