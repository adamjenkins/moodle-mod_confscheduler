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
 * Pure, framework-agnostic colour helpers shared by both the edit-mode grid
 * (amd/src/scheduler_grid.js) and the read-only Display mode grid
 * (amd/src/scheduler_display.js), so a room's or span-block's chosen
 * background colour never leaves illegible text on top of it (Revision round
 * 1 feedback, 2026-07-03).
 *
 * Formula choice: this uses the classic YIQ/"perceived brightness" formula
 * (R*299 + G*587 + B*114) / 1000, not the more precise gamma-corrected WCAG
 * relative-luminance formula. Both are acceptable for this purpose (picking
 * black vs. white text, not a strict WCAG contrast-ratio pass/fail); YIQ is
 * simpler, has no gamma-correction step, and its 0-255 output threshold
 * (128, i.e. the midpoint) is easy to reason about. If stricter WCAG
 * contrast-ratio compliance is ever required, swap perceivedBrightness()'s
 * body for the sRGB-linearised relative luminance formula and adjust the
 * threshold to sit against a real contrast-ratio calculation instead of a
 * flat midpoint -- callers only depend on contrastTextColour()'s return
 * value ('#000000' | '#ffffff' | null), not on this internal formula.
 *
 * Every function here is a pure function of its arguments (no DOM access, no
 * module-level state), matching amd/src/day_utils.js's shared-helper pattern.
 *
 * @module     mod_confscheduler/colour_utils
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Parses a 6-digit hex colour string (with or without a leading '#') into its
 * red/green/blue components.
 *
 * @param {String} hex A hex colour, e.g. "#3366cc" or "3366cc"
 * @return {{r: Number, g: Number, b: Number}|null} null if $hex is not a valid 6-digit hex colour
 */
export const hexToRgb = (hex) => {
    if (typeof hex !== 'string') {
        return null;
    }

    const match = (/^#?([0-9a-fA-F]{6})$/).exec(hex.trim());
    if (!match) {
        return null;
    }

    const sixdigits = match[1];
    return {
        r: parseInt(sixdigits.substring(0, 2), 16),
        g: parseInt(sixdigits.substring(2, 4), 16),
        b: parseInt(sixdigits.substring(4, 6), 16),
    };
};

/**
 * Computes the YIQ "perceived brightness" of a hex colour, on a 0 (black) to
 * 255 (white) scale. See this module's docblock for why this formula was
 * chosen over full WCAG relative luminance.
 *
 * @param {String} hex A hex colour, e.g. "#3366cc"
 * @return {Number|null} null if $hex is not a valid 6-digit hex colour
 */
export const perceivedBrightness = (hex) => {
    const rgb = hexToRgb(hex);
    if (!rgb) {
        return null;
    }

    return ((rgb.r * 299) + (rgb.g * 587) + (rgb.b * 114)) / 1000;
};

/**
 * Picks black or white text to sit legibly on top of a given background hex
 * colour, based on perceivedBrightness().
 *
 * @param {String} hex A background hex colour, e.g. "#3366cc"
 * @param {Number} threshold Brightness threshold (0-255 scale); >= this value picks black text
 * @return {String|null} '#000000', '#ffffff', or null if $hex is not a valid 6-digit hex colour
 * (callers should leave the element's text colour unset/default in that case)
 */
export const contrastTextColour = (hex, threshold = 128) => {
    const brightness = perceivedBrightness(hex);
    if (brightness === null) {
        return null;
    }

    return brightness >= threshold ? '#000000' : '#ffffff';
};
