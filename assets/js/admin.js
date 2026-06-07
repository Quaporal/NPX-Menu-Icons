/**
 * NPX Menu Icons — Admin Icon Picker
 *
 * Injects a compact icon button into each nav menu item's handle bar.
 * Clicking opens a modal matching the blocks icon picker design:
 *   - toolbar (search + style/fill/weight filters) at top
 *   - icon grid on the left (infinite scroll, lazy load)
 *   - settings sidebar on the right (position, size+unit, color swatches, hide-label toggle)
 *   - footer: icon count + link | Cancel / Remove Icon / Apply
 *
 * No build step - plain ES5 + jQuery (already in WP admin).
 *
 * @package NPX_Menu_Icons
 */

( function ( $ ) {
    'use strict';

    var icons        = ( typeof npxMenuIconsData !== 'undefined' ) ? ( npxMenuIconsData.icons       || [] ) : [];
    var themeColors  = ( typeof npxMenuIconsData !== 'undefined' ) ? ( npxMenuIconsData.themeColors || [] ) : [];
    var ajaxUrl      = ( typeof npxMenuIconsData !== 'undefined' ) ? ( npxMenuIconsData.ajaxUrl     || '' ) : '';
    var refreshNonce = ( typeof npxMenuIconsData !== 'undefined' ) ? ( npxMenuIconsData.nonce       || '' ) : '';
    var CHUNK        = 150;

    // State
    var state = {
        currentItemId : null,
        pending       : '',
        search        : '',
        cat           : '',
        style         : 'material-symbols-sharp',
        fill          : '0',
        weight        : '400',
        position      : 'before',
        sizeVal       : '1.5',
        sizeUnit      : 'em',
        color         : '',
        hideLabel     : false,
        offset        : 0,
        filtered      : [],
    };

    // The button that opened the modal — focus is restored here on close.
    var $lastTrigger = null;

    function parseSizeStr( sizeStr ) {
        var m = String( sizeStr || '1.5em' ).match( /^(\d+(?:\.\d+)?)(px|rem|em)?$/ );
        return m ? { val: m[1], unit: m[2] || 'em' } : { val: '1.5', unit: 'em' };
    }

    function isThemeColor( color ) {
        return themeColors.some( function ( c ) {
            return c.color && c.color.toLowerCase() === color.toLowerCase();
        } );
    }

    // HTML-escape for content contexts (no DOM allocation needed).
    function e( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;'  )
            .replace( />/g, '&gt;'  );
    }

    function ea( str ) {
        return String( str )
            .replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' )
            .replace( /'/g, '&#039;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
    }

    function buildCatOptions() {
        var seen = {}; var cats = [];
        icons.forEach( function ( icon ) {
            if ( icon.c && ! seen[ icon.c ] ) { seen[ icon.c ] = true; cats.push( icon.c ); }
        } );
        cats.sort();
        var html = '<option value="">All Categories</option>';
        cats.forEach( function ( c ) {
            var label = c.split( '_' ).map( function ( w ) { return w.charAt(0).toUpperCase() + w.slice(1); } ).join( ' ' );
            html += '<option value="' + ea(c) + '">' + label + '</option>';
        } );
        return html;
    }

    function buildColorSwatches() {
        var html = '<button type="button" class="npx-im-swatch npx-im-swatch--cc" data-color="" title="currentColor"></button>';
        themeColors.forEach( function ( c ) {
            html += '<button type="button" class="npx-im-swatch" data-color="' + ea(c.color) + '" title="' + ea(c.name) + '" style="--sw:' + ea(c.color) + '"></button>';
        } );
        html += '<button type="button" class="npx-im-swatch npx-im-swatch--custom" data-color="__custom__" title="Custom color">';
        html += '<svg width="12" height="12" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" fill="none">';
        html += '<path fill="#000000" fill-rule="evenodd" d="M9 17a1 1 0 102 0v-6h6a1 1 0 100-2h-6V3a1 1 0 10-2 0v6H3a1 1 0 000 2h6v6z"/>';
        html += '</svg>';
        html += '</button>';
        //
        return html;
    }

    var $modal = $( [
        '<div id="npxIconModal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="npx-im-title" aria-label="Set Menu Icon">',
        '  <div class="npx-im-wrap">',
        '    <div class="components-modal__header npx-im-head">',
        '      <div class="components-modal__header-heading-container">',
        '          <h2 id="npx-im-title" class="components-modal__header-heading">Set Menu Icon</h2>',
        '      </div>',
        '      <button type="button" class="components-button is-compact has-icon npx-im-btn-close" aria-label="Close">',
        '           <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">',
        '               <path d="m13.06 12 6.47-6.47-1.06-1.06L12 10.94 5.53 4.47 4.47 5.53 10.94 12l-6.47 6.47 1.06 1.06L12 13.06l6.47 6.47 1.06-1.06L13.06 12Z"></path>',
        '           </svg>',
        '        </button>',
        '    </div>',
        '    <div class="npx-im-toolbar">',
        '      <input type="search" class="npx-im-search" placeholder="Search icons\u2026" autocomplete="off">',
        '      <select class="npx-im-cat">' + buildCatOptions() + '</select>',
        '      <select class="npx-im-style">',
        '        <option value="material-symbols-sharp">Sharp</option>',
        '        <option value="material-symbols-rounded">Rounded</option>',
        '      </select>',
        '      <select class="npx-im-fill">',
        '        <option value="0">Outline</option>',
        '        <option value="1">Fill</option>',
        '      </select>',
        '      <select class="npx-im-weight">',
        '        <option value="100">Thin</option>',
        '        <option value="200">Extra Light</option>',
        '        <option value="300">Light</option>',
        '        <option value="400">Regular</option>',
        '        <option value="500">Medium</option>',
        '        <option value="600">Semi Bold</option>',
        '        <option value="700">Bold</option>',
        '      </select>',
        '      <button type="button" class="button npx-im-btn-reset">Reset</button>',
        '    </div>',
        '    <div class="npx-im-body">',
        '      <div class="npx-im-left">',
        '        <div class="npx-im-grid"></div>',
        '      </div>',
        '      <div class="npx-im-sidebar">',
        '        <div class="npx-im-sel">',
        '          <div class="npx-im-sel-glyph-wrap">',
        '            <span class="npx-im-sel-glyph material-symbols-sharp"></span>',
        '          </div>',
        '          <p class="npx-im-sel-name">No icon selected</p>',
        '        </div>',
        '        <div class="npx-im-settings">',
        '          <label class="npx-im-row">',
        '            <span>Position</span>',
        '            <select class="npx-im-position">',
        '              <option value="before">Before label</option>',
        '              <option value="after">After label</option>',
        '            </select>',
        '          </label>',
        '          <div class="npx-im-row">',
        '            <span>Size</span>',
        '            <div class="npx-im-size-wrap">',
        '              <input type="number" class="npx-im-size-val" min="0.5" max="4" step="0.25" value="1.5">',
        '              <select class="npx-im-size-unit">',
        '                <option value="em">em</option>',
        '                <option value="rem">rem</option>',
        '                <option value="px">px</option>',
        '              </select>',
        '            </div>',
        '          </div>',
        '          <div class="npx-im-row">',
        '            <span>Color</span>',
        '            <div class="npx-im-swatches">' + buildColorSwatches() + '</div>',
        '            <div class="npx-im-custom-wrap" style="display:none;">',
        '              <input type="color" class="npx-im-custom-native" value="#000000">',
        '              <input type="text"  class="npx-im-custom-text"   placeholder="#000000 or CSS value">',
        '            </div>',
        '          </div>',
        '          <div class="npx-im-row npx-im-row--toggle">',
        '            <span>Hide label</span>',
        '            <button type="button" role="switch" aria-checked="false" aria-label="Hide menu item label" class="npx-im-toggle">',
        '              <span class="npx-im-toggle-thumb"></span>',
        '            </button>',
        '          </div>',
        '        </div>',
        '      </div>',
        '    </div>',
        '    <div class="npx-im-footer">',
        '      <span class="npx-im-count"></span>',
        '      <button type="button" class="npx-im-btn-refresh components-button is-tertiary" title="Refresh icon list from Google Fonts">',
        '        <span class="material-symbols-sharp" style="font-size:16px;vertical-align:middle;">refresh</span>',
        '      </button>',
        '      <div class="npx-im-footer-btns">',
        '        <button type="button" class="components-button is-tertiary npx-im-btn-cancel">Cancel</button>',
        '        <button type="button" class="components-button is-secondary npx-im-btn-remove" disabled>Remove Icon</button>',
        '        <button type="button" class="components-button is-primary npx-im-btn-confirm" disabled>Apply</button>',
        '      </div>',
        '    </div>',
        '  </div>',
        '</div>',
    ].join( '' ) );

    $( 'body' ).append( $modal );

    function getFiltered() {
        var s = state.search; var c = state.cat;
        return icons.filter( function ( icon ) {
            if ( s && icon.n.indexOf(s) === -1 ) { return false; }
            if ( c && icon.c !== c )             { return false; }
            return true;
        } );
    }

    function fontVariation() { 
        return "'FILL' " + state.fill + ", 'wght' " + state.weight; 
    }

    function renderGrid() {
        state.filtered = getFiltered();
        state.offset   = 0;
        $modal.find( '.npx-im-grid' ).empty().scrollTop(0);
        appendItems();
        updateCount();
    }

    function appendItems() {
        var $grid = $modal.find( '.npx-im-grid' );
        var slice = state.filtered.slice( state.offset, state.offset + CHUNK );
        var fv    = fontVariation();
        if ( slice.length === 0 ) {
            if ( state.offset === 0 ) { $grid.html( '<p class="npx-im-empty">No icons match your search.</p>' ); }
            return;
        }
        var html = '';
        slice.forEach( function ( icon ) {
            var sel = state.pending === icon.n ? ' is-selected' : '';
            var label = icon.n.replace(/_/g,' ');
            html += '<div class="npx-im-item' + sel + '" role="option" tabindex="0" aria-selected="' + (state.pending === icon.n ? 'true' : 'false') + '" data-icon="' + ea(icon.n) + '" title="' + ea(label) + '">';
            html +=   '<span class="npx-im-glyph ' + ea(state.style) + '" style="font-variation-settings:' + ea(fv) + '" aria-hidden="true">' + e(icon.n) + '</span>';
            html +=   '<small>' + e(label) + '</small>';
            html += '</div>';
        } );
        $grid.append( html );
        state.offset += slice.length;
    }

    function updateCount() {
        $modal.find( '.npx-im-count' ).html(
            e(state.filtered.length) + ' icons \u2014 <a href="https://fonts.google.com/icons?icon.set=Material+Symbols" target="_blank" rel="noopener noreferrer">Material Symbols</a>'
        );
    }

    function updateGlyphStyle() {
        var fv = fontVariation();
        $modal.find( '.npx-im-glyph' )
            .removeClass( 'material-symbols-sharp material-symbols-rounded' )
            .addClass( state.style )
            .css( 'font-variation-settings', fv );
        updateSelPreview();
    }

    function updateSelPreview() {
        var fv = fontVariation();
        var $g = $modal.find( '.npx-im-sel-glyph' );
        var $n = $modal.find( '.npx-im-sel-name' );
        if ( state.pending ) {
            $g.attr( 'class', 'npx-im-sel-glyph ' + state.style )
              .css( 'font-variation-settings', fv )
              .css( 'color', state.color || '' )
              .text( state.pending )
              .show();
            $n.text( state.pending.replace(/_/g,' ') );
        } else {
            $g.hide().text('');
            $n.text( 'No icon selected' );
        }
    }

    function syncColorSwatches( color ) {
        var $sw = $modal.find( '.npx-im-swatch' );
        var $cw = $modal.find( '.npx-im-custom-wrap' );
        $sw.removeClass( 'is-selected' );
        if ( color === '' ) {
            $modal.find( '.npx-im-swatch--cc' ).addClass( 'is-selected' );
            $cw.hide();
        } else if ( isThemeColor(color) ) {
            $sw.each( function () {
                var sc = $( this ).data('color');
                if ( sc && sc !== '__custom__' && sc.toLowerCase() === color.toLowerCase() ) { $( this ).addClass('is-selected'); }
            } );
            $cw.hide();
        } else {
            $modal.find( '.npx-im-swatch--custom' ).addClass( 'is-selected' );
            $cw.show();
            $modal.find( '.npx-im-custom-text' ).val( color );
            if ( /^#[0-9a-f]{6}$/i.test(color) ) { $modal.find( '.npx-im-custom-native' ).val( color ); }
        }
    }

    function updateSizeConstraints( unit ) {
        var $v = $modal.find( '.npx-im-size-val' );
        if ( unit === 'px' ) { $v.attr({ min:'8', max:'128', step:'1' }); }
        else                 { $v.attr({ min:'0.5', max:'4', step:'0.25' }); }
    }

    function openModal( itemId ) {
        state.currentItemId = itemId;
        $lastTrigger = $( '.npx-mi-handle-btn[data-item-id="' + itemId + '"]' );
        var $li     = $( '#menu-item-' + itemId );
        var $inputs = $li.find( '.npx-mi-inputs' );

        state.pending   = $inputs.find( '.npx-mi-inp-name'     ).val() || '';
        state.style     = $inputs.find( '.npx-mi-inp-style'    ).val() || 'material-symbols-sharp';
        state.fill      = $inputs.find( '.npx-mi-inp-fill'     ).val() || '0';
        state.weight    = $inputs.find( '.npx-mi-inp-weight'   ).val() || '400';
        state.position  = $inputs.find( '.npx-mi-inp-position' ).val() || 'before';
        state.color     = $inputs.find( '.npx-mi-inp-color'    ).val() || '';
        state.hideLabel = $inputs.find( '.npx-mi-inp-hide'     ).val() === '1';
        state.search    = '';
        state.cat       = '';

        var sz = parseSizeStr( $inputs.find( '.npx-mi-inp-size' ).val() );
        state.sizeVal  = sz.val;
        state.sizeUnit = sz.unit;

        $modal.find( '.npx-im-search'  ).val('');
        $modal.find( '.npx-im-cat'     ).val('');
        $modal.find( '.npx-im-style'   ).val( state.style );
        $modal.find( '.npx-im-fill'    ).val( state.fill );
        $modal.find( '.npx-im-weight'  ).val( state.weight );
        $modal.find( '.npx-im-position'  ).val( state.position );
        $modal.find( '.npx-im-size-val'  ).val( state.sizeVal );
        $modal.find( '.npx-im-size-unit' ).val( state.sizeUnit );
        updateSizeConstraints( state.sizeUnit );

        $modal.find( '.npx-im-toggle' )
            .attr( 'aria-checked', state.hideLabel ? 'true' : 'false' )
            .toggleClass( 'is-on', state.hideLabel );

        syncColorSwatches( state.color );

        var hasIcon = !! state.pending;
        $modal.find( '.npx-im-btn-confirm' ).prop( 'disabled', !hasIcon );
        $modal.find( '.npx-im-btn-remove'  ).prop( 'disabled', !hasIcon );

        updateSelPreview();
        renderGrid();
        $modal.fadeIn(120);
        setTimeout( function () { $modal.find('.npx-im-search').trigger('focus'); }, 130 );
    }

    function closeModal() {
        var $trigger = $lastTrigger;
        $lastTrigger = null;
        state.currentItemId = null;
        $modal.fadeOut( 120, function () {
            // Return keyboard focus to the button that opened the modal.
            if ( $trigger && $trigger.length ) {
                $trigger.trigger( 'focus' );
            }
        } );
    }

    function applySelection() {
        // Guard: modal may have been closed before this fires.
        if ( ! state.currentItemId ) { return; }
        var itemId  = state.currentItemId;
        var $li     = $( '#menu-item-' + itemId );
        var $inputs = $li.find( '.npx-mi-inputs' );
        var $btn    = $li.find( '.npx-mi-handle-btn' );
        var fv      = "'FILL' " + state.fill + ", 'wght' " + state.weight;
        var sizeStr = state.sizeVal + state.sizeUnit;

        $inputs.find( '.npx-mi-inp-name'     ).val( state.pending );
        $inputs.find( '.npx-mi-inp-style'    ).val( state.style );
        $inputs.find( '.npx-mi-inp-fill'     ).val( state.fill );
        $inputs.find( '.npx-mi-inp-weight'   ).val( state.weight );
        $inputs.find( '.npx-mi-inp-position' ).val( state.position );
        $inputs.find( '.npx-mi-inp-size'     ).val( sizeStr );
        $inputs.find( '.npx-mi-inp-color'    ).val( state.color );
        $inputs.find( '.npx-mi-inp-hide'     ).val( state.hideLabel ? '1' : '0' );

        if ( state.pending ) {
            $btn.addClass('has-icon').attr( 'title', state.pending.replace(/_/g,' ') );
            $btn.find('span').attr( 'class', state.style ).attr( 'style', 'font-variation-settings:' + fv + ';' ).text( state.pending );
        } else {
            $btn.removeClass('has-icon').attr('title','Add icon');
            $btn.find('span').attr('class','material-symbols-sharp npx-mi-add-glyph').removeAttr('style').text('add_circle');
        }

        closeModal();
    }

    // ── Bindings ───────────────────────────────────────────────────

    $( document ).on( 'click', '.npx-mi-handle-btn', function () { openModal( $(this).data('item-id') ); } );

    $modal.on( 'click', '.npx-im-btn-close, .npx-im-btn-cancel', closeModal );
    $modal.on( 'click', function (ev) { if ( $(ev.target).is('#npxIconModal') ) { closeModal(); } } );
    $( document ).on( 'keydown', function (ev) { if ( ev.key === 'Escape' && $modal.is(':visible') ) { closeModal(); } } );

    $modal.on( 'click', '.npx-im-btn-confirm', applySelection );
    $modal.on( 'click', '.npx-im-btn-remove', function () { state.pending = ''; applySelection(); } );

    var searchTimer = null;
    $modal.on( 'input', '.npx-im-search', function () {
        var val = $(this).val().toLowerCase().trim();
        clearTimeout(searchTimer);
        searchTimer = setTimeout( function () { state.search = val; renderGrid(); }, 220 );
    } );

    $modal.on( 'change', '.npx-im-cat',    function () { state.cat    = $(this).val(); renderGrid(); } );
    $modal.on( 'change', '.npx-im-style',  function () { state.style  = $(this).val(); updateGlyphStyle(); } );
    $modal.on( 'change', '.npx-im-fill',   function () { state.fill   = $(this).val(); updateGlyphStyle(); } );
    $modal.on( 'change', '.npx-im-weight', function () { state.weight = $(this).val(); updateGlyphStyle(); } );

    $modal.on( 'click', '.npx-im-btn-reset', function () {
        state.search = ''; state.cat = ''; state.style = 'material-symbols-sharp'; state.fill = '0'; state.weight = '400';
        $modal.find('.npx-im-search').val(''); $modal.find('.npx-im-cat').val('');
        $modal.find('.npx-im-style').val(state.style); $modal.find('.npx-im-fill').val(state.fill); $modal.find('.npx-im-weight').val(state.weight);
        renderGrid(); updateGlyphStyle();
    } );

    $modal.on( 'change', '.npx-im-position',  function () { state.position = $(this).val(); } );
    $modal.on( 'input',  '.npx-im-size-val',  function () { state.sizeVal  = $(this).val(); } );
    $modal.on( 'change', '.npx-im-size-unit', function () { state.sizeUnit = $(this).val(); updateSizeConstraints(state.sizeUnit); } );

    $modal.on( 'click', '.npx-im-swatch', function () {
        var color = $(this).data('color');
        if ( color === '__custom__' ) {
            $modal.find('.npx-im-swatch').removeClass('is-selected');
            $(this).addClass('is-selected');
            $modal.find('.npx-im-custom-wrap').show();
            if ( state.color !== '' && !isThemeColor(state.color) ) {
                $modal.find('.npx-im-custom-text').val(state.color);
                if ( /^#[0-9a-f]{6}$/i.test(state.color) ) { $modal.find('.npx-im-custom-native').val(state.color); }
            }
        } else {
            state.color = color;
            syncColorSwatches(color);
            updateSelPreview();
        }
    } );

    $modal.on( 'input', '.npx-im-custom-native', function () {
        var hex = $(this).val(); $modal.find('.npx-im-custom-text').val(hex); state.color = hex; updateSelPreview();
    } );

    $modal.on( 'input', '.npx-im-custom-text', function () {
        var val = $(this).val().trim(); state.color = val;
        if ( /^#[0-9a-f]{6}$/i.test(val) ) { $modal.find('.npx-im-custom-native').val(val); }
        updateSelPreview();
    } );

    $modal.on( 'click', '.npx-im-toggle', function () {
        state.hideLabel = !state.hideLabel;
        $(this).attr('aria-checked', state.hideLabel ? 'true' : 'false').toggleClass('is-on', state.hideLabel);
    } );

    $modal.on( 'click', '.npx-im-item', function () {
        state.pending = $(this).data('icon');
        $modal.find('.npx-im-item').removeClass('is-selected').attr('aria-selected','false');
        $(this).addClass('is-selected').attr('aria-selected','true');
        $modal.find('.npx-im-btn-confirm').prop('disabled', false);
        $modal.find('.npx-im-btn-remove').prop('disabled', false);
        updateSelPreview();
    } );

    // Keyboard: Enter or Space selects; Enter on already-selected applies.
    $modal.on( 'keydown', '.npx-im-item', function ( ev ) {
        if ( ev.key === 'Enter' || ev.key === ' ' ) {
            ev.preventDefault();
            var icon = $(this).data('icon');
            if ( ev.key === 'Enter' && state.pending === icon ) {
                applySelection();
            } else {
                state.pending = icon;
                $modal.find('.npx-im-item').removeClass('is-selected').attr('aria-selected','false');
                $(this).addClass('is-selected').attr('aria-selected','true');
                $modal.find('.npx-im-btn-confirm').prop('disabled', false);
                $modal.find('.npx-im-btn-remove').prop('disabled', false);
                updateSelPreview();
            }
        }
    } );

    $modal.on( 'dblclick', '.npx-im-item', function () { state.pending = $(this).data('icon'); applySelection(); } );

    $modal.find( '.npx-im-grid' ).on( 'scroll', function () {
        var el = this;
        if ( el.scrollTop + el.clientHeight >= el.scrollHeight - 80 ) {
            if ( state.offset < state.filtered.length ) { appendItems(); }
        }
    } );

    // Refresh icon list from Google Fonts — writes to file, reloads page on success
    $modal.on( 'click', '.npx-im-btn-refresh', function () {
        var $btn  = $( this );
        var $spin = $btn.find( 'span' );
        var $cnt  = $modal.find( '.npx-im-count' );

        $btn.prop( 'disabled', true );
        $spin.css( 'animation', 'npx-spin 1s linear infinite' );
        $cnt.text( 'Refreshing\u2026' );

        $.post( ajaxUrl, { action: 'npx_mi_refresh_icons', nonce: refreshNonce } )
            .done( function ( res ) {
                if ( res.success ) {
                    $cnt.text( 'Done! ' + res.data.count + ' icons updated. Reloading\u2026' );
                    setTimeout( function () { window.location.reload(); }, 1200 );
                } else {
                    var msg = res.data && res.data.message ? res.data.message : 'Unknown error';
                    $cnt.text( 'Error: ' + msg );
                    $btn.prop( 'disabled', false );
                    $spin.css( 'animation', '' );
                }
            } )
            .fail( function () {
                $cnt.text( 'Request failed.' );
                $btn.prop( 'disabled', false );
                $spin.css( 'animation', '' );
            } );
    } );

} )( jQuery );