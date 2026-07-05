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
 * Year/month/day/hour/minute <select> group helpers, used in place of a plain
 * `<input type="datetime-local">` for the autoscheduler and span-block modals
 * (amd/src/scheduler_grid.js). A native datetime-local input's on-screen value
 * is rendered in whatever date order/separator the browser's own locale uses
 * (e.g. mm/dd/yyyy for an en-US browser) -- the page has no way to override
 * that display, even though the underlying value attribute is always ISO
 * (yyyy-mm-ddThh:mm). That ambiguity is exactly what the rest of this plugin's
 * mforms avoid by using Moodle's own `date_time_selector` element (see
 * mod_form.php's conferencestart/conferenceend), which renders as explicit
 * year/month/day/hour/minute selects instead of relying on a native widget.
 * These two AJAX modals are plain Mustache/JS, not mforms, so this module
 * reimplements that same select-group idea for them.
 *
 * @module     mod_confscheduler/datetime_select_utils
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const pad2 = (value) => String(value).padStart(2, '0');

/**
 * Builds the option-list context (years/months/days/hours/minutes) for
 * rendering a datetime select-group Mustache template. Years span from one
 * year before now to five years after -- generous enough for any realistically
 * scheduled conference without an unbounded/unwieldy dropdown.
 *
 * @return {Object} {years, months, days, hours, minutes} - each an array of zero-padded strings
 */
export const buildDateTimeSelectOptions = () => {
    const thisyear = new Date().getFullYear();
    const years = [];
    for (let year = thisyear - 1; year <= thisyear + 5; year++) {
        years.push(String(year));
    }

    const months = [];
    for (let month = 1; month <= 12; month++) {
        months.push(pad2(month));
    }

    const days = [];
    for (let day = 1; day <= 31; day++) {
        days.push(pad2(day));
    }

    const hours = [];
    for (let hour = 0; hour <= 23; hour++) {
        hours.push(pad2(hour));
    }

    const minutes = [];
    for (let minute = 0; minute <= 55; minute += 5) {
        minutes.push(pad2(minute));
    }

    return {years, months, days, hours, minutes};
};

/**
 * Sets a datetime select-group's five selects to represent the given unix
 * timestamp (local timezone, the same convention the rest of this plugin's
 * client-side date handling already uses).
 *
 * @param {Element} root The element containing the five selects (e.g. a modal's root)
 * @param {String} prefix The select-group's name prefix, e.g. "windowstart"
 * @param {Number} timestamp Unix timestamp (seconds)
 */
export const setDateTimeSelectGroup = (root, prefix, timestamp) => {
    const date = new Date(timestamp * 1000);
    root.querySelector(`[name=${prefix}_year]`).value = String(date.getFullYear());
    root.querySelector(`[name=${prefix}_month]`).value = pad2(date.getMonth() + 1);
    root.querySelector(`[name=${prefix}_day]`).value = pad2(date.getDate());
    root.querySelector(`[name=${prefix}_hour]`).value = pad2(date.getHours());
    root.querySelector(`[name=${prefix}_minute]`).value = pad2(Math.floor(date.getMinutes() / 5) * 5);
};

/**
 * Reads a datetime select-group's five selects back out as a unix timestamp
 * (local timezone).
 *
 * @param {Element} root The element containing the five selects (e.g. a modal's root)
 * @param {String} prefix The select-group's name prefix, e.g. "windowstart"
 * @return {Number} Unix timestamp (seconds)
 */
export const getDateTimeSelectGroupTimestamp = (root, prefix) => {
    const year = Number(root.querySelector(`[name=${prefix}_year]`).value);
    const month = Number(root.querySelector(`[name=${prefix}_month]`).value);
    const day = Number(root.querySelector(`[name=${prefix}_day]`).value);
    const hour = Number(root.querySelector(`[name=${prefix}_hour]`).value);
    const minute = Number(root.querySelector(`[name=${prefix}_minute]`).value);
    return Math.floor(new Date(year, month - 1, day, hour, minute).getTime() / 1000);
};
