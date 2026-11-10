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
 * Formula choice (2026-11-10): the WCAG 2.x sRGB relative luminance and
 * contrast ratio. contrastTextColour() computes the contrast ratio of the
 * background against both black and white text and returns whichever is
 * higher, so the choice is the one that reads best by the WCAG measure. It
 * replaced an earlier YIQ "perceived brightness" midpoint test, which picked
 * white text on some mid-tone backgrounds (saturated oranges, greens) where
 * black has the better contrast ratio. Callers only depend on
 * contrastTextColour()'s return value ('#000000' | '#ffffff' | null).
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
 * Converts one 0-255 sRGB channel to its linear-light value (WCAG 2.x).
 *
 * @param {Number} channel The 0-255 channel value
 * @return {Number} The linearised value, 0-1
 */
const linearise = (channel) => {
    const c = channel / 255;
    return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
};

/**
 * Computes the WCAG 2.x relative luminance of a hex colour, on a 0 (black) to
 * 1 (white) scale.
 *
 * @param {String} hex A hex colour, e.g. "#3366cc"
 * @return {Number|null} null if $hex is not a valid 6-digit hex colour
 */
export const relativeLuminance = (hex) => {
    const rgb = hexToRgb(hex);
    if (!rgb) {
        return null;
    }

    return (0.2126 * linearise(rgb.r)) + (0.7152 * linearise(rgb.g)) + (0.0722 * linearise(rgb.b));
};

/**
 * Computes the WCAG 2.x contrast ratio (1 to 21) between two relative
 * luminances, given in either order.
 *
 * @param {Number} first A relative luminance, 0-1
 * @param {Number} second A relative luminance, 0-1
 * @return {Number} The contrast ratio
 */
export const contrastRatio = (first, second) => {
    const lighter = Math.max(first, second);
    const darker = Math.min(first, second);
    return (lighter + 0.05) / (darker + 0.05);
};

/**
 * Picks black or white text to sit legibly on top of a given background hex
 * colour: whichever has the higher WCAG contrast ratio against it (black wins
 * a tie).
 *
 * @param {String} hex A background hex colour, e.g. "#3366cc"
 * @return {String|null} '#000000', '#ffffff', or null if $hex is not a valid 6-digit hex colour
 * (callers should leave the element's text colour unset/default in that case)
 */
export const contrastTextColour = (hex) => {
    const luminance = relativeLuminance(hex);
    if (luminance === null) {
        return null;
    }

    return contrastRatio(luminance, 0) >= contrastRatio(luminance, 1) ? '#000000' : '#ffffff';
};
