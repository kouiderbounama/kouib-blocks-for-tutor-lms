/* Quick course search — kouib/courses-search (Vanilla JS, no jQuery) */
( function () {
	'use strict';

	/**
	 * debounce: runs fn after events stop for wait milliseconds.
	 */
	function debounce( fn, wait ) {
		var timer = null;
		return function () {
			var args = arguments;
			var ctx = this;
			clearTimeout( timer );
			timer = setTimeout( function () {
				fn.apply( ctx, args );
			}, wait );
		};
	}

	/**
	 * Search component: reads the display options from the data-attributes and builds the request.
	 */
	function KouibSearch( root ) {
		this.root = root;
		this.input = root.querySelector( '.kouib-search-input' );
		this.results = root.querySelector( '.kouib-search-results' );
		this.spinner = root.querySelector( '.kouib-search-spinner' );
		this.url = root.getAttribute( 'data-kouibs-rest' ) || '';
		this.perPage = parseInt( root.getAttribute( 'data-kouibs-perpage' ) || '6', 10 );
		this.openNewTab = root.getAttribute( 'data-kouibs-open' ) === '1';
		this.errorMsg = root.getAttribute( 'data-kouibs-error' ) || 'Error';
		this.controller = null;
		this.lastQuery = '';

		this.onInput = debounce( this.fetchResults.bind( this ), 300 );
		this.bind();
	}

	KouibSearch.prototype.bind = function () {
		if ( ! this.input ) {
			return;
		}

		this.input.addEventListener( 'input', this.onInput );
		this.input.addEventListener( 'keydown', this.handleKeydown.bind( this ) );

		// Closes the list when clicking outside the block or pressing Escape.
		// Note: in overlay mode the panel is moved to body whenever it is outside root,
		// so clicks inside the moved panel are also excluded so the link receives the click.
		var self = this;
		document.addEventListener( 'mousedown', function ( e ) {
			if ( self.root.contains( e.target ) ) {
				return;
			}
			if ( self.results && self.results.contains( e.target ) ) {
				return;
			}
			self.close();
		} );
		this.input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				self.close();
				self.input.value = '';
			}
		} );
	};

	KouibSearch.prototype.handleKeydown = function ( e ) {
		var items = this.results ? this.results.querySelectorAll( '.kouib-search-item' ) : [];
		if ( ! items.length ) {
			return;
		}

		var index = Array.prototype.indexOf.call( items, document.activeElement );

		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			index = ( index + 1 ) % items.length;
			this.setActive( items, index );
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			index = ( index - 1 + items.length ) % items.length;
			this.setActive( items, index );
		} else if ( e.key === 'Enter' && index >= 0 ) {
			e.preventDefault();
			items[ index ].click();
		}
	};

	KouibSearch.prototype.setActive = function ( items, index ) {
		this.results.setAttribute( 'aria-activedescendant', 'kouib-search-result-' + index );
		items.forEach( function ( item, i ) {
			item.setAttribute( 'aria-selected', i === index ? 'true' : 'false' );
		} );
		items[ index ].focus();
	};

	KouibSearch.prototype.fetchResults = function () {
		var q = this.input.value.trim();

		// Abort any previously pending request
		if ( this.controller ) {
			this.controller.abort();
			this.controller = null;
		}

		if ( '' === q ) {
			this.close();
			return;
		}

		if ( q === this.lastQuery ) {
			return;
		}
		this.lastQuery = q;

		this.setLoading( true );
		this.openPanel();

		var params = new URLSearchParams();
		params.set( 'q', q );
		params.set( 'perPage', String( this.perPage ) );
		params.set( 'showThumb', rootattr( this.root, 'data-kouibs-showthumb' ) );
		params.set( 'showPrice', rootattr( this.root, 'data-kouibs-showprice' ) );
		params.set( 'showRating', rootattr( this.root, 'data-kouibs-showrating' ) );
		params.set( 'showStudents', rootattr( this.root, 'data-kouibs-showstudents' ) );
		params.set( 'openInNewTab', this.openNewTab ? '1' : '0' );
		params.set( 'sentence', rootattr( this.root, 'data-kouibs-phrase' ) );

		this.controller = new AbortController();

		var self = this;
		fetch( this.url + '?' + params.toString(), {
			signal: this.controller.signal,
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'Kouib search HTTP ' + res.status );
				}
				return res.json();
			} )
			.then( function ( data ) {
				self.render( data.html || '' );
			} )
			.catch( function ( err ) {
				if ( err && err.name === 'AbortError' ) {
					return; // The old request was aborted — we ignore it silently
				}
				self.render(
					'<p class="kouib-search-no-results">' + escapeHtml( self.errorMsg ) + '</p>'
				);
			} )
			.finally( function () {
				self.setLoading( false );
			} );
	};

	KouibSearch.prototype.openPanel = function () {
		if ( ! this.results ) {
			return;
		}

		// In overlay mode (not "inline"): move the panel to body so it rises above any
		// stacking or clipping context (overflow/transform/...) the template imposes on ancestors.
		if ( ! this.results.classList.contains( 'kouib-search-inline' ) ) {
			this.pinToBody();
			this.positionPanel();

			var self = this;
			this._onReposition = function () {
				self.positionPanel();
			};
			window.addEventListener( 'scroll', this._onReposition, true );
			window.addEventListener( 'resize', this._onReposition );
		}

		this.results.removeAttribute( 'hidden' );
		this.input.setAttribute( 'aria-expanded', 'true' );
		this.root.classList.add( 'kouib-search-open' );
	};

	KouibSearch.prototype.close = function () {
		this.lastQuery = '';
		if ( this.controller ) {
			this.controller.abort();
			this.controller = null;
		}
		this.setLoading( false );
		if ( this.results ) {
			this.results.setAttribute( 'hidden', 'true' );
			this.results.innerHTML = '';
		}
		this.input.setAttribute( 'aria-expanded', 'false' );
		this.input.removeAttribute( 'aria-activedescendant' );

		if ( this._onReposition ) {
			window.removeEventListener( 'scroll', this._onReposition, true );
			window.removeEventListener( 'resize', this._onReposition );
			this._onReposition = null;
		}
		this.unpinFromBody();
		this.root.classList.remove( 'kouib-search-open' );
	};

	/**
	 * Moves the panel to the end of body (leaving a placeholder where it was)
	 * so it escapes any stacking/clipping context in its ancestors.
	 */
	KouibSearch.prototype.pinToBody = function () {
		if ( this.results.parentNode === document.body ) {
			return;
		}
		this._placeholder = document.createComment( 'kouib-search-results' );
		this.results.parentNode.insertBefore( this._placeholder, this.results );
		document.body.appendChild( this.results );
		this.results.classList.add( 'kouib-search-portal' );
	};

	/**
	 * Returns the panel to its original position and clears the inline positioning styles.
	 */
	KouibSearch.prototype.unpinFromBody = function () {
		if ( this._placeholder && this._placeholder.parentNode ) {
			this._placeholder.parentNode.insertBefore( this.results, this._placeholder );
			this._placeholder.remove();
		}
		this._placeholder = null;
		if ( this.results ) {
			this.results.classList.remove( 'kouib-search-portal' );
			// Removes all inline positioning styles (position/top/left/width/right) so the
			// panel returns to its normal look (absolute inside the wrapper) when restored
			this.results.removeAttribute( 'style' );
		}
	};

	/**
	 * Positions the moved panel in a fixed position under the field using viewport coordinates.
	 */
	KouibSearch.prototype.positionPanel = function () {
		if ( ! this.results || ! this.results.classList.contains( 'kouib-search-portal' ) ) {
			return;
		}
		var r = this.input.getBoundingClientRect();
		this.results.style.position = 'fixed';
		this.results.style.top = ( r.bottom + 6 ) + 'px';
		this.results.style.left = r.left + 'px';
		this.results.style.width = r.width + 'px';
		this.results.style.right = 'auto';
	};

	KouibSearch.prototype.render = function ( html ) {
		this.results.innerHTML = html;
	};

	KouibSearch.prototype.setLoading = function ( on ) {
		if ( this.spinner ) {
			this.spinner.toggleAttribute( 'hidden', ! on );
		}
		this.root.classList.toggle( 'kouib-search-loading', on );
	};

	function rootattr( root, name ) {
		return root.getAttribute( name ) || '0';
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}

	function initAll() {
		document.querySelectorAll( '[data-kouib-search]' ).forEach( function ( el ) {
			if ( el && ! el.__kouibSearch ) {
				el.__kouibSearch = new KouibSearch( el );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
} )();