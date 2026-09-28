<?php
/**
 * Inline SVG icons, used everywhere the site once used emojis.
 *
 * One list serves both sides: kop_icon('name') in PHP, and kopIcon('name')
 * in JavaScript (the same list printed into every page as window.KOP_ICONS).
 * Icons are 24x24 stroke glyphs in the Lucide style (ISC licence), sized to
 * 1em and drawn in currentColor, so they take the size and colour of the text
 * around them. Plain PHP with no WordPress calls, so standalone api/ pages can
 * require this file directly.
 */

if (!function_exists('kop_icon_paths')) {
    function kop_icon_paths() {
        return array(
            'alert-circle'   => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
            'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'archive'        => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
            'arrow-up'       => '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>',
            'bar-chart'      => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
            'bell'           => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
            'book'           => '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/>',
            'bot'            => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
            'candle'         => '<path d="M12 2c1.5 2 2 3 2 4a2 2 0 0 1-4 0c0-1 .5-2 2-4z"/><rect x="9" y="10" width="6" height="12" rx="1"/>',
            'building'       => '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
            'calendar'       => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
            'check'          => '<path d="M20 6 9 17l-5-5"/>',
            'check-circle'   => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
            'clipboard'      => '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
            'dot'            => '<circle cx="12" cy="12" r="6" fill="currentColor" stroke="none"/>',
            'download'       => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
            'eraser'         => '<path d="m7 21-4.3-4.3c-1-1-1-2.5 0-3.4l9.6-9.6c1-1 2.5-1 3.4 0l5.6 5.6c1 1 1 2.5 0 3.4L13 21"/><path d="M22 21H7"/><path d="m5 11 9 9"/>',
            'eye'            => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
            'file-plus'      => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M9 15h6"/><path d="M12 18v-6"/>',
            'file-text'      => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
            'flask'          => '<path d="M10 2v7.527a2 2 0 0 1-.211.896L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.069-10.127A2 2 0 0 1 14 9.527V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/>',
            'folder'         => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
            'folder-open'    => '<path d="m6 14 1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2"/>',
            'globe'          => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
            'graduation-cap' => '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
            'home'           => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
            'hammer'         => '<path d="m15 12-8.373 8.373a1 1 0 1 1-3-3L12 9"/><path d="m18 15 4-4"/><path d="m21.5 11.5-1.914-1.914A2 2 0 0 1 19 8.172V7l-2.26-2.26a6 6 0 0 0-4.202-1.756L9 2.96l.92.82A6.18 6.18 0 0 1 12 8.4V10l2 2h1.172a2 2 0 0 1 1.414.586L18.5 14.5"/>',
            'hospital'       => '<path d="M12 6v4"/><path d="M14 14h-4"/><path d="M14 18h-4"/><path d="M14 8h-4"/><path d="M18 12h2a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2h2"/><path d="M18 22V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v18"/>',
            'hourglass'      => '<path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/>',
            'id-card'        => '<rect width="20" height="14" x="2" y="5" rx="2"/><circle cx="8" cy="12" r="2"/><path d="M14 10h4"/><path d="M14 14h4"/>',
            'inbox'          => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'landmark'       => '<path d="M3 22h18"/><path d="M6 18v-7"/><path d="M10 18v-7"/><path d="M14 18v-7"/><path d="M18 18v-7"/><path d="M12 2 20 7H4z"/>',
            'laptop'         => '<path d="M20 16V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v9m16 0H4m16 0 1.28 2.55a1 1 0 0 1-.9 1.45H3.62a1 1 0 0 1-.9-1.45L4 16"/>',
            'lightbulb'      => '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
            'life-buoy'      => '<circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 4.24 4.24"/><path d="m14.83 9.17 4.24-4.24"/><path d="m14.83 14.83 4.24 4.24"/><path d="m9.17 14.83-4.24 4.24"/><circle cx="12" cy="12" r="4"/>',
            'link'           => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'lock'           => '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'map-pin'        => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
            'megaphone'      => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
            'microscope'     => '<path d="M6 18h8"/><path d="M3 22h18"/><path d="M14 22a7 7 0 1 0 0-14h-1"/><path d="M9 14h2"/><path d="M9 12a2 2 0 0 1-2-2V6h6v4a2 2 0 0 1-2 2Z"/><path d="M12 6V3a1 1 0 0 0-1-1H9a1 1 0 0 0-1 1v3"/>',
            'mountain'       => '<path d="m8 3 4 8 5-5 5 15H2L8 3z"/>',
            'newspaper'      => '<path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/>',
            'notebook'       => '<path d="M2 6h4"/><path d="M2 10h4"/><path d="M2 14h4"/><path d="M2 18h4"/><rect width="16" height="20" x="4" y="2" rx="2"/><path d="M16 2v20"/>',
            'package'        => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 8.7 5 8.7-5"/>',
            'palette'        => '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
            'pause'          => '<rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/>',
            'pen-line'       => '<path d="M12 20h9"/><path d="M16.38 3.62a1 1 0 0 1 3 3L7.37 18.64a2 2 0 0 1-.85.5l-2.87.84a.5.5 0 0 1-.62-.62l.84-2.87a2 2 0 0 1 .5-.85z"/>',
            'pencil'         => '<path d="M21.17 6.81a1 1 0 0 0-3.98-3.98L3.84 16.17a2 2 0 0 0-.5.83l-1.32 4.35a.5.5 0 0 0 .62.62l4.35-1.32a2 2 0 0 0 .83-.5z"/><path d="m15 5 4 4"/>',
            'phone-off'      => '<rect width="12" height="20" x="6" y="2" rx="2"/><path d="M12 18h.01"/><path d="m2 2 20 20"/>',
            'plus'           => '<path d="M5 12h14"/><path d="M12 5v14"/>',
            'receipt'        => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
            'refresh'        => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
            'rocket'         => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
            'save'           => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
            'scale'          => '<path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/>',
            'scroll'         => '<path d="M19 17V5a2 2 0 0 0-2-2H4"/><path d="M8 21h12a2 2 0 0 0 2-2v-1a1 1 0 0 0-1-1H11a1 1 0 0 0-1 1v1a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v2a1 1 0 0 0 1 1h3"/>',
            'search'         => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'send'           => '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/>',
            'settings'       => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
            'shield'         => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
            'shuffle'        => '<path d="m18 14 4 4-4 4"/><path d="m18 2 4 4-4 4"/><path d="M2 18h1.973a4 4 0 0 0 3.3-1.7l5.454-7.6a4 4 0 0 1 3.3-1.7H22"/><path d="M2 6h1.972a4 4 0 0 1 3.6 2.2"/><path d="M22 18h-6.041a4 4 0 0 1-3.3-1.8l-.359-.45"/>',
            'siren'          => '<path d="M7 18v-6a5 5 0 1 1 10 0v6"/><path d="M5 21a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-1a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2z"/><path d="M21 12h1"/><path d="M18.5 4.5 18 5"/><path d="M2 12h1"/><path d="M12 2v1"/><path d="m4.929 4.929.707.707"/><path d="M12 12v6"/>',
            'sparkles'       => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15v4"/><path d="M17 17h4"/>',
            'square'         => '<rect width="18" height="18" x="3" y="3" rx="2"/>',
            'square-check'   => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="m9 12 2 2 4-4"/>',
            'tag'            => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
            'target'         => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
            'trash'          => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/>',
            'trophy'         => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
            'tv'             => '<rect width="20" height="15" x="2" y="7" rx="2"/><path d="m17 2-5 5-5-5"/>',
            'upload'         => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
            'user'           => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'user-x'         => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m17 8 5 5"/><path d="m22 8-5 5"/>',
            'users'          => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'van'            => '<path d="M8 6v6"/><path d="M15 6v6"/><path d="M2 12h19.6"/><path d="M18 18h3s.5-1.7.8-2.8c.1-.4.2-.8.2-1.2 0-.4-.1-.8-.2-1.2l-1.4-5C20.1 6.8 19.1 6 18 6H4a2 2 0 0 0-2 2v10h3"/><circle cx="7" cy="18" r="2"/><path d="M9 18h5"/><circle cx="16" cy="18" r="2"/>',
            'wrench'         => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
            'x'              => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'x-circle'       => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        );
    }
}

if (!function_exists('kop_icon')) {
    /**
     * Inline SVG markup for one icon, or '' for an unknown name.
     *
     * @param string $name  Key of kop_icon_paths().
     * @param array  $opts  'class' => extra classes, 'label' => accessible
     *                      name (otherwise the icon is aria-hidden).
     */
    function kop_icon($name, $opts = array()) {
        $paths = kop_icon_paths();
        if (!isset($paths[$name])) {
            return '';
        }
        $class = 'kop-icon kop-icon-' . $name;
        if (!empty($opts['class'])) {
            $class .= ' ' . $opts['class'];
        }
        $a11y = !empty($opts['label'])
            ? 'role="img" aria-label="' . htmlspecialchars($opts['label'], ENT_QUOTES, 'UTF-8') . '"'
            : 'aria-hidden="true"';
        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" ' . $a11y
            . ' focusable="false" width="1em" height="1em" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            . ' style="display:inline-block;vertical-align:-0.125em;flex-shrink:0">'
            . $paths[$name] . '</svg>';
    }
}

if (!function_exists('kop_icons_js')) {
    /** The JS side: window.KOP_ICONS and window.kopIcon(name, opts). */
    function kop_icons_js() {
        return 'window.KOP_ICONS=' . json_encode(kop_icon_paths(), JSON_UNESCAPED_SLASHES) . ';'
            . 'window.kopIcon=function(n,o){o=o||{};var p=window.KOP_ICONS[n];if(!p)return"";'
            . 'var e=function(s){return String(s).replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c];});};'
            . 'var a=o.label?\'role="img" aria-label="\'+e(o.label)+\'"\':\'aria-hidden="true"\';'
            . 'return \'<svg class="kop-icon kop-icon-\'+n+(o.class?" "+e(o.class):"")+\'" \'+a+'
            . '\' focusable="false" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-0.125em;flex-shrink:0">\'+p+"</svg>";};';
    }
}

if (!function_exists('kop_emoji_icon_map')) {
    /**
     * Emojis that editors typed into post content, widgets and ACF fields,
     * with the icon that replaces each. The facility-key emojis are values of
     * the ACF "Icons" checkbox (stored as the emoji itself), so they keep their
     * legend meaning as the icon's accessible name.
     */
    function kop_emoji_icon_map() {
        return array(
            // Facility key (ACF field "icons" and the "facility key" legend block)
            "\u{1F56F}" => array('candle', 'Child deaths reported'),
            "\u{1F4D3}" => array('notebook', 'Handbooks available'),
            "\u{1F198}" => array('life-buoy', 'Survivor reports of abuse available'),
            "\u{1F4F0}" => array('newspaper', 'News coverage available'),
            "\u{2696}"  => array('scale', 'Legal documents available'),
            "\u{1F6A8}" => array('siren', 'Police or emergency reports available'),
            "\u{26A0}"  => array('alert-triangle', 'Inspection violation reports available'),
            "\u{1F9EA}" => array('flask', 'Pseudoscience used'),
            "\u{1F4FA}" => array('tv', 'Media depictions available'),
            "\u{1F9FE}" => array('receipt', 'Ads and marketing materials available'),
            "\u{1F9D1}\u{200D}\u{2708}" => array('user-x', 'Staff arrests or criminal records available'),
            "\u{1F4F5}" => array('phone-off', 'Restricted communication reported by survivors'),
            "\u{2692}"  => array('hammer', 'Forced labor reported by survivors'),
            "\u{26F0}"  => array('mountain', 'Wilderness programming'),
            // Everything else found in published content
            "\u{2705}"  => array('check-circle', ''),
            "\u{274C}"  => array('x-circle', ''),
            "\u{2757}"  => array('alert-circle', ''),
            "\u{2699}"  => array('settings', ''),
            "\u{2795}"  => array('plus', ''),
            "\u{1F30D}" => array('globe', ''),
            "\u{1F3E2}" => array('building', ''),
            "\u{1F465}" => array('users', ''),
            "\u{1F4CA}" => array('bar-chart', ''),
            "\u{1F4CB}" => array('clipboard', ''),
            "\u{1F4EE}" => array('send', ''),
            "\u{1F50D}" => array('search', ''),
            "\u{1F50E}" => array('search', ''),
            "\u{1F5D1}" => array('trash', ''),
            "\u{1F3C6}" => array('trophy', ''),
            "\u{1F4A1}" => array('lightbulb', ''),
            "\u{1F4C1}" => array('folder', ''),
            "\u{1F4C5}" => array('calendar', ''),
            "\u{1F4E6}" => array('package', ''),
            "\u{1F504}" => array('refresh', ''),
            "\u{1F680}" => array('rocket', ''),
        );
    }
}

if (!function_exists('kop_replace_emojis_in_html')) {
    /**
     * Swap mapped emojis for SVG icons in the text of an HTML fragment.
     * Only text between tags is touched; scripts, styles and comments are left
     * as they are, and emojis inside tags, <option>, <title> and <textarea>
     * (which cannot hold markup) are dropped instead.
     */
    function kop_replace_emojis_in_html($html) {
        static $pattern = null;
        static $map = null;
        if (!is_string($html) || $html === '' || !preg_match('/[\x{2692}-\x{2757}\x{2795}\x{1F198}-\x{1F9FF}]/u', $html)) {
            return $html;
        }
        if ($pattern === null) {
            $map = kop_emoji_icon_map();
            $keys = array_keys($map);
            usort($keys, function ($a, $b) { return strlen($b) - strlen($a); });
            $pattern = '/(' . implode('|', array_map(function ($k) { return preg_quote($k, '/'); }, $keys)) . ')\x{FE0F}*/u';
        }
        $parts = preg_split(
            '/(<!--.*?-->|<script\b.*?<\/script>|<style\b.*?<\/style>|<(?:option|title|textarea)\b.*?<\/(?:option|title|textarea)>|<[^>]*>)/is',
            $html, -1, PREG_SPLIT_DELIM_CAPTURE
        );
        if ($parts === false) {
            return $html;
        }
        foreach ($parts as $i => $part) {
            if ($part === '' || !preg_match($pattern, $part)) {
                continue;
            }
            if ($i % 2 === 1) {
                if (preg_match('/^<(?:!--|script\b|style\b)/i', $part)) {
                    continue;
                }
                $parts[$i] = preg_replace('/' . substr($pattern, 1, -2) . ' ?/u', '', $part);
                continue;
            }
            $parts[$i] = preg_replace_callback($pattern, function ($m) use ($map) {
                list($name, $label) = $map[$m[1]];
                return kop_icon($name, $label !== '' ? array('label' => $label) : array());
            }, $part);
        }
        return implode('', $parts);
    }
}

if (function_exists('add_action')) {
    // Emojis stored in post content, block widgets and ACF fields shown via
    // Meta Field Block all pass through render_block; classic content and text
    // widgets get the same treatment.
    add_filter('render_block', 'kop_replace_emojis_in_html', 20);
    add_filter('the_content', 'kop_replace_emojis_in_html', 20);
    add_filter('widget_text', 'kop_replace_emojis_in_html', 20);

    /**
     * Print kopIcon() in the head of every front-end and admin page, ahead of
     * any theme script, so scripts can call it without declaring a dependency.
     */
    function kop_enqueue_icons() {
        wp_register_script('kop-icons', false, array(), null, false);
        wp_enqueue_script('kop-icons');
        wp_add_inline_script('kop-icons', kop_icons_js());
    }
    add_action('wp_enqueue_scripts', 'kop_enqueue_icons', 1);
    add_action('admin_enqueue_scripts', 'kop_enqueue_icons', 1);
}
