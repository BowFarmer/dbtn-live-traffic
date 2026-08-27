/**
 * Live Traffic admin panel.
 *
 * Standalone version of the original tab1 handler. Reads its config from the
 * `dbtn_traffic_obj` global (localized by DBTN_Traffic::enqueue_assets) instead
 * of the host plugin's shared `dbtn_rest_objects.tab1`.
 *
 * Behavior preserved from the original:
 *   - Live polling of access.log every refresh_rate ms (pause/resume).
 *   - Sub-tabs for 403-404, PHP Errors, PHP Slow, WAF Log, WP-Cron (lazy + cached).
 *   - "Hide static assets", "Hide me", and HTTP status client-side row filters.
 *   - Click an IP to copy it and look it up via ipinfo.io (auto-pauses live).
 *   - Option/Alt-click an IP to filter live traffic to that IP.
 *   - Click a time to copy the displayed row as tab-separated values.
 *   - Click a path to copy it; Option-click also copies its referrer.
 */
jQuery( document ).ready(
	function ($) {

		const cfg = window.dbtn_traffic_obj;

		if ( ! cfg || ! cfg.rest_url) {
			// Panel not on this screen, or config missing.
			return;
		}

		let paused                 = false;
		let ltTimer                = null;
		let activeLogTab           = 'live';
		let loadedReports          = {};
		let lastVisitorCountFetch  = 0;
		let urlSearchTerm          = '';
		let ipHeaderSortActive     = false;
		let pathHeaderSortActive   = false;
		let uaHeaderSortActive     = false;
		let geoHeaderSortActive    = false;
		let limitIP                = '';
		const visitorCountInterval = 60000;
		
		const allowedLineCounts = [ 50, 100, 200, 500, 1000, 2500 ];
		const linesStorageKey   = 'dbtn_live_traffic_lines:' + (cfg.current_user || 'default');
		
		let savedLines = 0;
		
		try {
			savedLines = parseInt( window.localStorage.getItem( linesStorageKey ), 10 );
		} catch (error) {
			// Storage may be unavailable due to browser privacy settings.
		}
		
		const configuredLines = parseInt( cfg.lines, 10 );
		
		let ltLines = allowedLineCounts.includes( savedLines )
			? savedLines
			: (allowedLineCounts.includes( configuredLines ) ? configuredLines : 500);		

		const $content            = $( cfg.replace_obj );
		const $pauseBtn           = $( '#dbtn-lt-pause' );
		const $statusBadge        = $( '#dbtn-lt-status' );
		const $updated            = $( '#dbtn-lt-updated' );
		const $dbVersion          = $( '#dbtn-db-version' );
		const $linesSelect        = $( '#dbtn-lt-lines' );
		const $hideStaticChk      = $( '#dbtn-lt-hide-static' );
		const $hideMeChk          = $( '#dbtn-lt-hide-me' );
		const $hideWpJsonChk      = $( '#dbtn-lt-hide-wp-json' );
		const $statusFilterSelect = $( '#dbtn-lt-status-filter' );
		const $urlSearchInput     = $( '#dbtn-lt-url-search' );
		const $urlSearchButton    = $( '#dbtn-lt-url-search-button' );

		if ( ! $content.length) {
			console.error( 'DBTN Live Traffic: target not found:', cfg.replace_obj );
			return;
		}

		const STATIC_EXT_RE = /\.(jpe?g|webp|png|gif|svg|ico|bmp|tiff?|js|mjs|ftl|css|woff2?|ttf|eot|otf|map|txt|xml|pdf|zip|gz|tar|icc|wasm)(\?|#|$)/i;
		const currentUser   = cfg.current_user;

		const reportTabs = [
		{ id: 'live', label: 'Live Traffic', url: cfg.rest_url },
		{ id: '403-404', label: '403-404', url: cfg.rest_403_404_url || routeUrl( 'log-403-404' ) },
		{ id: 'php-errors', label: 'PHP Errors', url: cfg.rest_php_errors_url || routeUrl( 'php-errors' ) },
		{ id: 'php-slow', label: 'PHP Slow', url: cfg.rest_php_slow_url || routeUrl( 'php-slow' ) },
		{ id: 'waf-log', label: 'WAF Log', url: cfg.rest_waf_log_url || routeUrl( 'waf-log' ) },
		{ id: 'wp-cron', label: 'WP-Cron', url: cfg.rest_wp_cron_url || routeUrl( 'wp-cron' ) }
		];

		if (cfg.rest_visitors_url) {
			reportTabs.push( { id: 'visitors', label: 'Visitors', url: cfg.rest_visitors_url } );
		}
		reportTabs.push( { id: 'downloads', label: 'Download', url: cfg.rest_downloads_url || routeUrl( 'downloads' ) } );

		$linesSelect.val( String( ltLines ) );

		function routeUrl(route) {
			return String( cfg.rest_url ).replace( /\/admin\/live-traffic\/?(?:\?.*)?$/, '/admin/' + route );
		}

		function setActiveLogTab(tabId) {
			const tab = reportTabs.find(
				function (item) {
					return item.id === tabId;
				}
			);

			if ( ! tab) {
				return;
			}

			activeLogTab = tab.id;

			$( '.dbtn-lt-log-tab' ).each(
				function () {
					const $button  = $( this );
					const isActive = $button.attr( 'data-dbtn-log-tab' ) === activeLogTab;
					$button.toggleClass( 'is-active', isActive ).attr( 'aria-selected', isActive ? 'true' : 'false' );
				}
			);

			$( '#dbtn-lt-report-refresh' ).toggle( activeLogTab !== 'live' );
			setLiveTrafficControlsState( activeLogTab === 'live' );

			if (activeLogTab === 'live') {
				if ( ! paused) {
					setStatusLive();
					fetchTraffic();
				}
				return;
			}

			setStatusPausedForReport();
			fetchReport( tab, false );
		}

		function setLiveTrafficControlsState(isLive) {
			$pauseBtn.prop( 'disabled', ! isLive );
			$linesSelect.prop( 'disabled', ! isLive );
			$hideStaticChk.prop( 'disabled', ! isLive );
			$hideMeChk.prop( 'disabled', ! isLive );
			$hideWpJsonChk.prop( 'disabled', ! isLive );
			$statusFilterSelect.prop( 'disabled', ! isLive );
			$urlSearchInput.prop( 'disabled', ! isLive );
			$urlSearchButton.prop( 'disabled', ! isLive );
		}

		function setStatusLive() {
			$statusBadge
			.removeClass( 'dbtn-lt-status-paused' )
			.addClass( 'dbtn-lt-status-live' )
			.html( '&#9679; LIVE' );
		}

		function setStatusPaused() {
			$statusBadge
			.removeClass( 'dbtn-lt-status-live' )
			.addClass( 'dbtn-lt-status-paused' )
			.html( '&#9646;&#9646; PAUSED' );
		}

		function setStatusPausedForReport() {
			$statusBadge
			.removeClass( 'dbtn-lt-status-live' )
			.addClass( 'dbtn-lt-status-paused' )
			.html( '&#9646;&#9646; LIVE TRAFFIC PAUSED' );
		}

		function setStatusPausedForIpTraffic() {
			$statusBadge
			.removeClass( 'dbtn-lt-status-live' )
			.addClass( 'dbtn-lt-status-paused' )
			.html( '&#9646;&#9646; IP TRAFFIC' );
		}

		function resetLiveTrafficSorting() {
			ipHeaderSortActive   = false;
			pathHeaderSortActive = false;
			uaHeaderSortActive   = false;
			geoHeaderSortActive  = false;

			$content.find( 'th.db_col_header.dbtn-lt-col-ip' )
				.removeAttr( 'aria-sort' );

			$content.find( 'th.db_col_header.dbtn-lt-col-path' )
				.text( 'Path' )
				.removeAttr( 'aria-sort' );

			$content.find( 'th.db_col_header.dbtn-lt-col-ua' )
				.text( 'Browser / Bot' )
				.removeAttr( 'aria-sort' );

			$content.find( 'th.db_col_header.dbtn-lt-col-geo' )
				.text( 'Location' )
				.removeAttr( 'aria-sort' );

			updateIpHeader();
		}

		function statusMatchesFilter(status, filter) {
			const statusCode = parseInt( status, 10 );

			if (filter === 'all' || ! filter) {
				return true;
			}

			if ( ! Number.isFinite( statusCode )) {
				return false;
			}

			if (filter === '2xx') {
				return statusCode >= 200 && statusCode <= 299;
			}

			if (filter === '3xx') {
				return statusCode >= 300 && statusCode <= 399;
			}

			if (filter === '4xx') {
				return statusCode >= 400 && statusCode <= 499;
			}

			return true;
		}

		function updateIpHeader() {
			const $header = $content.find( 'th.db_col_header.dbtn-lt-col-ip' );

			if (limitIP) {
				$header.text( 'IP-' + limitIP );
			} else if (ipHeaderSortActive) {
				$header.text( 'IP-sorted' );
			} else {
				$header.text( 'IP' );
			}
		}

		function applyFilters() {
			const hideStatic   = $hideStaticChk.is( ':checked' );
			const hideMe       = $hideMeChk.is( ':checked' );
			const hideWpJson   = $hideWpJsonChk.is( ':checked' );
			const statusFilter = $statusFilterSelect.val() || 'all';
			const urlNeedle    = urlSearchTerm.toLocaleLowerCase();

			$content.find( '.dbtn-lt-table tbody tr' ).each(
				function () {
					const $row = $( this );

					const path =
					$row.find( '.dbtn-lt-col-path' ).attr( 'title' ) ||
					$row.find( '.dbtn-lt-col-path' ).text();

					const ipText =
					$row.find( '.dbtn-lt-col-ip' ).attr( 'title' ) ||
					$row.find( '.dbtn-lt-col-ip' ).text();

					const statusText =
					$row.attr( 'data-status' ) ||
					$row.find( '.dbtn-lt-col-status' ).text();

					const isStatic      = hideStatic && STATIC_EXT_RE.test( path );
					const isMe          = hideMe && ipText.toLowerCase().includes( currentUser.toLowerCase() );
					const isWpJson      = hideWpJson && /^\/wp-json\/dbtn\/v2\/validation(?:\/|[?#]|$)/i.test( path );
					const isStatusMatch = statusMatchesFilter( statusText, statusFilter );
					const isUrlMatch    = ! urlNeedle || String( path ).toLocaleLowerCase().includes( urlNeedle );
					const rowIP         = extractIp( ipText );
					const isIpMatch     = ! limitIP || rowIP === limitIP;

					$row.toggle( ! isStatic && ! isMe && ! isWpJson && isStatusMatch && isUrlMatch && isIpMatch );
				}
			);

			updateIpHeader();
		}

		function runUrlSearch() {
			urlSearchTerm = String( $urlSearchInput.val() || '' ).trim();
			$urlSearchButton.toggleClass( 'is-active', '' !== urlSearchTerm );
			applyFilters();
		}

		function toggleUrlSearch() {
			if ('' !== urlSearchTerm) {
				urlSearchTerm = '';
				$urlSearchInput.val( '' );
				$urlSearchButton.removeClass( 'is-active' );
				applyFilters();
				return;
			}

			runUrlSearch();
		}

		function updateVisitorsButton(count) {
			const $button = $( '.dbtn-lt-log-tab[data-dbtn-log-tab="visitors"]' );

			if ( ! $button.length) {
				return;
			}

			const numericCount = parseInt( count, 10 );

			if (Number.isNaN( numericCount )) {
				return;
			}

			$button.text( 'Visitors ' + numericCount.toLocaleString() );
		}

		function addCopyTrafficButton() {
			const $heading = $content.find( '.dbtn-lt-ip-traffic-heading' );

			if ( ! $heading.length) {
				return;
			}

			$heading.append(
				`<button type="button"
					class="dbtn-lt-copy-ip-traffic"
					title="Copy selected traffic to clipboard">
					<svg
						viewBox="0 0 24 24"
						aria-hidden="true"
						stroke="currentColor"
						stroke-width="var(--stroke-width, 1.2)">

						<rect
							class="back-sheet"
							x="9"
							y="4"
							width="10"
							height="12.7"
							rx="1"
							ry="1"
							fill="#f0efec"/>

						<rect
							class="front-sheet"
							x="5"
							y="8"
							width="10"
							height="12.7"
							rx="1"
							ry="1"
							fill="#faf9f6"/>
					</svg>
				</button>`
			);
		}

		function fetchTraffic() {
			if (activeLogTab !== 'live' || paused || document.visibilityState !== 'visible') {
				return;
			}

			const nowMs                   = Date.now();
			const shouldFetchVisitorCount = Boolean( cfg.rest_visitors_url ) && (nowMs - lastVisitorCountFetch >= visitorCountInterval);
			let url                       = cfg.rest_url + '?lines=' + encodeURIComponent( String( ltLines ) );

			if (shouldFetchVisitorCount) {
				url                  += '&visitor_count=1';
				lastVisitorCountFetch = nowMs;
			}

			fetch(
				url,
				{
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': cfg.nonce
					}
				}
			)
				.then(
					function (response) {
						if ( ! response.ok) {
							throw new Error( 'HTTP ' + response.status );
						}

						return response.json();
					}
				)
				.then(
					function (response) {
						if ( ! response || typeof response.new_content !== 'string') {
							throw new Error( 'Missing new_content in REST response.' );
						}

						if (activeLogTab !== 'live' || paused) {
							return;
						}

						$content.html( response.new_content );

						if (response.nonce) {
							cfg.nonce = response.nonce;
						}

						if (Object.prototype.hasOwnProperty.call( response, 'today_visitors' )) {
							updateVisitorsButton( response.today_visitors );
						}

						if (Object.prototype.hasOwnProperty.call( response, 'geolite_version' )) {
							$dbVersion.text( 'GeoLite2-City.mmdb version ' + response.geolite_version );
						}

						applyFilters();

						const now = new Date();
						$updated.text( 'Updated ' + now.toLocaleTimeString() );
					}
				)
				.catch(
					function (error) {
						console.error( 'DBTN Live Traffic fetch error:', error );
					}
				);
		}

		function fetchReport(tab, forceRefresh) {

			const cacheable = ! ['wp-cron', 'php-errors', 'visitors', 'downloads'].includes( tab.id );
			if (cacheable && ! forceRefresh && loadedReports[tab.id]) {
				$content.html( loadedReports[tab.id] );
				return;
			}

			$content.html( '<p class="dbtn-lt-loading">Loading ' + $( '<span>' ).text( tab.label ).html() + '…</p>' );

			fetch(
				tab.url,
				{
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': cfg.nonce
					}
				}
			)
				.then(
					function (response) {
						if ( ! response.ok) {
							throw new Error( 'HTTP ' + response.status );
						}
						return response.json();
					}
				)
				.then(
					function (response) {
						if ( ! response || typeof response.new_content !== 'string') {
							throw new Error( 'Missing new_content in REST response.' );
						}

						if (response.nonce) {
							cfg.nonce = response.nonce;
						}

						loadedReports[tab.id] = response.new_content;

						if (activeLogTab === tab.id) {
							$content.html( response.new_content );
							const now = new Date();
							$updated.text( 'Loaded ' + tab.label + ' ' + now.toLocaleTimeString() );
						}
					}
				)
				.catch(
					function (error) {
						console.error( 'DBTN log report fetch error:', error );
						$content.html( '<p class="dbtn-lt-error">Could not load ' + $( '<span>' ).text( tab.label ).html() + '.</p>' );
					}
				);
		}

		function addIpTrafficSorting() {
			const $table = $content.find( '.dbtn-lt-ip-traffic-table' );
		
			if ( ! $table.length ) {
				return;
			}
		
			const sortColumns = {
				'.dbtn-lt-col-time': 'text',
				'.dbtn-lt-col-path': 'text',
				'.dbtn-lt-col-bytes': 'number'
			};
		
			Object.entries( sortColumns ).forEach(
				function ( [selector, type] ) {
					const $header = $table.find( selector );
		
					if ( ! $header.length ) {
						return;
					}
		
					let direction = 'desc';
		
					$header.css( 'cursor', 'pointer' );
		
					$header.on(
						'click',
						function () {
							const $tbody = $table.find( 'tbody' );
							const rows = $tbody.find( 'tr' ).get();
		
							rows.sort(
								function (a, b) {
									const aValue = $( a ).find( selector ).text().trim();
									const bValue = $( b ).find( selector ).text().trim();
		
									let comparison;
		
									if ( type === 'number' ) {
										comparison = ( parseInt( aValue, 10 ) || 0 ) -
											( parseInt( bValue, 10 ) || 0 );
									} else {
										comparison = aValue.localeCompare( bValue );
									}
		
									return direction === 'asc' ? comparison : -comparison;
								}
							);
		
							$tbody.append( rows );
		
							direction = direction === 'asc' ? 'desc' : 'asc';
						}
					);
				}
			);
		}

		function showIpTraffic(ip) {
			if ( ! ip) {
				return;
			}

			if (activeLogTab !== 'live') {
				setActiveLogTab( 'live' );
			}

			if ( ! paused) {
				$pauseBtn.trigger( 'click' );
			}

			setStatusPausedForIpTraffic();

			const url = (cfg.rest_ip_traffic_url || routeUrl( 'ip-traffic' )) +
			'?ip=' + encodeURIComponent( ip ) +
			'&lines=500&scan_lines=50000';

			$content.html(
				'<p class="dbtn-lt-loading">Loading access.log entries for <code>' +
				$( '<span>' ).text( ip ).html() +
				'</code>…</p>'
			);

			fetch(
				url,
				{
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': cfg.nonce
					}
				}
			)
				.then(
					function (response) {
						if ( ! response.ok) {
							throw new Error( 'HTTP ' + response.status );
						}
						return response.json();
					}
				)
				.then(
					function (response) {
						if ( ! response || typeof response.new_content !== 'string') {
							throw new Error( 'Missing new_content in REST response.' );
						}

						if (response.nonce) {
							cfg.nonce = response.nonce;
						}

						$content.html( response.new_content );
						addIpTrafficSorting();
						addCopyTrafficButton();
						applyFilters();
						const now = new Date();
						$updated.text( 'Loaded IP traffic ' + now.toLocaleTimeString() );
					}
				)
				.catch(
					function (error) {
						console.error( 'DBTN IP traffic fetch error:', error );
						$content.html(
							'<p class="dbtn-lt-error">Could not load access.log entries for <code>' +
							$( '<span>' ).text( ip ).html() +
							'</code>.</p>'
						);
					}
				);
		}

		function copyText(text) {
			if (navigator.clipboard && window.isSecureContext) {
				return navigator.clipboard.writeText( text );
			}

			return new Promise(
				function (resolve, reject) {
					const $textarea = $( '<textarea>' )
						.val( text )
						.css(
							{
								position: 'fixed',
								top: '-9999px',
								left: '-9999px'
							}
						)
						.appendTo( 'body' );

					$textarea[0].focus();
					$textarea[0].select();

					try {
						if (document.execCommand( 'copy' )) {
							resolve();
						} else {
							reject( new Error( 'Copy command was not accepted.' ) );
						}
					} catch (error) {
						reject( error );
					} finally {
						$textarea.remove();
					}
				}
			);
		}

		function showCopiedBadge(x, y) {
			$( '.dbtn-lt-copied-badge' ).remove();

			const $badge = $( '<span>', { class: 'dbtn-lt-copied-badge', role: 'status', text: 'Copied' } )
				.css(
					{
						position: 'fixed',
						zIndex: 100000,
						padding: '4px 8px',
						borderRadius: '4px',
						background: '#1d2327',
						color: '#fff',
						fontSize: '12px',
						lineHeight: '1.4',
						pointerEvents: 'none',
						boxShadow: '0 2px 6px rgba(0, 0, 0, 0.25)'
					}
				)
				.appendTo( 'body' );

			$badge.css(
				{
					left: Math.max( 8, Math.min( x + 10, window.innerWidth - $badge.outerWidth() - 8 ) ),
					top: Math.max( 8, Math.min( y + 10, window.innerHeight - $badge.outerHeight() - 8 ) )
				}
			);

			setTimeout(
				function () {
					$badge.fadeOut( 150, function () { $badge.remove(); } );
				},
				850
			);
		}

		function rowClipboardText($row) {
			const ip = $row.find( '.dbtn-lt-col-ip' ).clone()
				.children( '.dbtn-lt-ip-card' ).remove().end()
				.text().trim();
			const path = $row.find( '.dbtn-lt-col-path' ).clone()
				.children().remove().end()
				.text().trim();

			return [
				$row.find( '.dbtn-lt-col-time' ).text().trim(),
				ip,
				$row.find( '.dbtn-lt-col-geo' ).text().trim(),
				$row.find( '.dbtn-lt-col-method' ).text().trim(),
				path,
				$row.find( '.dbtn-lt-col-path .dbtn-lt-referer' ).first().text().trim(),
				$row.find( '.dbtn-lt-col-status' ).text().trim(),
				$row.find( '.dbtn-lt-col-bytes' ).text().trim(),
				$row.find( '.dbtn-lt-col-ua' ).text().trim()
			].join( '\t' );
		}

		function startTimer() {
			if (ltTimer) {
				clearInterval( ltTimer );
			}

			ltTimer = setInterval( fetchTraffic, parseInt( cfg.refresh_rate, 10 ) || 5000 );
		}

		// Inject the Refresh button (hidden until a report tab is active). The tab
		// buttons themselves are rendered server-side by DBTN_Traffic::render_panel.
		if ( ! $( '#dbtn-lt-report-refresh' ).length) {
			$( '#dbtn-lt-log-tabs' ).append(
				'<button type="button" id="dbtn-lt-report-refresh" class="button button-small dbtn-lt-report-refresh" style="display:none;">Refresh</button>'
			);
		}

		$( document ).on(
			'click',
			'.dbtn-lt-log-tab',
			function () {
				setActiveLogTab( $( this ).attr( 'data-dbtn-log-tab' ) || 'live' );
			}
		);

		$( document ).on(
			'click',
			'#dbtn-lt-report-refresh',
			function () {
				const tab = reportTabs.find(
					function (item) {
						return item.id === activeLogTab;
					}
				);

				if (tab && tab.id !== 'live') {
					delete loadedReports[tab.id];
					fetchReport( tab, true );
				}
			}
		);

		$urlSearchButton.on( 'click', toggleUrlSearch );

		$urlSearchInput.on(
			'keydown',
			function (event) {
				if ('Enter' === event.key) {
					event.preventDefault();
					runUrlSearch();
				}
			}
		);

		$pauseBtn.on(
			'click',
			function () {
				if (activeLogTab !== 'live') {
					return;
				}

				paused = ! paused;

				if (paused) {
					$pauseBtn.text( 'Resume' );
					setStatusPaused();
				} else {
					resetLiveTrafficSorting();
					$pauseBtn.text( 'Pause' );
					setStatusLive();
					fetchTraffic();
				}
			}
		);

		$( document ).on(
			'click',
			'.dbtn-lt-copy-ip-traffic',
			function (event) {
				event.preventDefault();

				const lines = [];

				$content.find( '.dbtn-lt-table tbody tr:visible' ).each(
					function () {
						const $row = $( this );

						const values = [
						$row.find( '.dbtn-lt-col-time' ).text().trim(),
						$row.find( '.dbtn-lt-col-ip' ).text().trim(),
						$row.find( '.dbtn-lt-col-geo' ).text().trim(),
						$row.find( '.dbtn-lt-col-method' ).text().trim(),
						$row.find( '.dbtn-lt-col-path' ).clone()
							.children()
							.remove()
							.end()
							.text()
							.trim(),
						$row.find( '.dbtn-lt-col-path .dbtn-lt-referer' ).text().trim(),
						$row.find( '.dbtn-lt-col-status' ).text().trim(),
						$row.find( '.dbtn-lt-col-bytes' ).text().trim(),
						$row.find( '.dbtn-lt-col-ua' ).text().trim()
						];

						lines.push( values.join( '\t' ) );
					}
				);

				if ( ! lines.length) {
					return;
				}

				navigator.clipboard.writeText( lines.reverse().join( '\n' ) )
				.then(
					function () {
						const $button = $( event.currentTarget );
						const $svg    = $button.find( 'svg' );

						const oldSvg = $svg.html();

						$svg.html(
							`
							<path d="M5 12l4 4L19 6"
								fill="none"
								stroke="currentColor"
								stroke-width="2"
								stroke-linecap="round"
								stroke-linejoin="round"/>
							`
						);

						setTimeout(
							function () {
								$svg.html( oldSvg );
							},
							1500
						);
					}
				)
				.catch(
					function (error) {
						console.error( 'DBTN Live Traffic clipboard error:', error );
					}
				);
			}
		);

		$hideStaticChk.on( 'change', applyFilters );
		$hideMeChk.on( 'change', applyFilters );
		$hideWpJsonChk.on( 'change', applyFilters );
		$statusFilterSelect.on( 'change', applyFilters );

		$linesSelect.on(
			'change',
			function () {
				const selectedLines = parseInt( $( this ).val(), 10 );
		
				ltLines = allowedLineCounts.includes( selectedLines )
					? selectedLines
					: 500;
		
				try {
					window.localStorage.setItem( linesStorageKey, String( ltLines ) );
				} catch (error) {
					// Continue normally if storage is unavailable.
				}
		
				fetchTraffic();
			}
		);

		document.addEventListener(
			'visibilitychange',
			function () {
				if (activeLogTab === 'live' && ! paused && document.visibilityState === 'visible') {
					fetchTraffic();
				}
			}
		);

		fetchTraffic();
		startTimer();

		$( document ).on(
			'click',
			'.dbtn-lt-visitors-sort',
			function () {
				const $button          = $( this );
				const $table           = $button.closest( '.dbtn-lt-visitors-table' );
				const column           = $button.attr( 'data-sort-column' );
				const currentDirection = $table.attr( 'data-sort-direction' ) || 'desc';
				const currentColumn    = $table.attr( 'data-sort-column' ) || 'date';
				const direction        = currentColumn === column && currentDirection === 'desc' ? 'asc' : 'desc';
				const rows             = $table.find( 'tbody tr' ).get();

				rows.sort(
					function (a, b) {
						const aValue     = $( a ).attr( column === 'count' ? 'data-count' : 'data-date' ) || '';
						const bValue     = $( b ).attr( column === 'count' ? 'data-count' : 'data-date' ) || '';
						const comparison = column === 'count'
						? parseInt( aValue, 10 ) - parseInt( bValue, 10 )
						: aValue.localeCompare( bValue );

						return direction === 'asc' ? comparison : -comparison;
					}
				);

				$table.attr( 'data-sort-column', column ).attr( 'data-sort-direction', direction );
				$table.find( '.dbtn-lt-visitors-sort' ).removeClass( 'is-sorted-asc is-sorted-desc' );
				$button.addClass( direction === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc' );
				$table.find( 'tbody' ).append( rows );
			}
		);

		$( document ).on(
			'click',
			'th.db_col_header.dbtn-lt-col-path',
			function () {
				if (activeLogTab !== 'live') {
					return;
				}

				const $header = $( this );
				const $table  = $header.closest( '.dbtn-lt-table' );

				if ( ! $table.length || $table.hasClass( 'dbtn-lt-ip-traffic-table' )) {
					return;
				}

				if (limitIP) {
					limitIP = '';
					applyFilters();
					return;
				}

				if (pathHeaderSortActive) {
					resetLiveTrafficSorting();

					if (paused) {
						$pauseBtn.trigger( 'click' );
					} else {
						fetchTraffic();
					}

					return;
				}

				if ( ! paused) {
					$pauseBtn.trigger( 'click' );
				}

				const $tbody = $table.find( 'tbody' );
				const rows   = $tbody.find( 'tr' ).get();

				rows.sort(
					function (a, b) {
						const $aCell = $( a ).find( 'td.dbtn-lt-col-path' );
						const $bCell = $( b ).find( 'td.dbtn-lt-col-path' );
						const aPath  = $aCell.attr( 'title' ) || $aCell.clone().children().remove().end().text().trim();
						const bPath  = $bCell.attr( 'title' ) || $bCell.clone().children().remove().end().text().trim();

						return aPath.localeCompare( bPath, undefined, {
							numeric: true,
							sensitivity: 'base'
						} );
					}
				);

				ipHeaderSortActive = false;
				$content.find( 'th.db_col_header.dbtn-lt-col-ip' )
					.text( 'IP' )
					.removeAttr( 'aria-sort' );
				uaHeaderSortActive = false;
				$content.find( 'th.db_col_header.dbtn-lt-col-ua' )
					.text( 'Browser / Bot' )
					.removeAttr( 'aria-sort' );
				geoHeaderSortActive = false;
				$content.find( 'th.db_col_header.dbtn-lt-col-geo' )
					.text( 'Location' )
					.removeAttr( 'aria-sort' );
				$tbody.append( rows );
				$header.text( 'Path-sorted' ).attr( 'aria-sort', 'ascending' );
				pathHeaderSortActive = true;
			}
		);

		$( document ).on(
			'click',
			'td.dbtn-lt-col-time',
			function (event) {
				copyText( rowClipboardText( $( this ).closest( 'tr' ) ) )
					.then( function () { showCopiedBadge( event.clientX, event.clientY ); } )
					.catch( function (error) { console.error( 'DBTN row clipboard error:', error ); } );
			}
		);

		$( document ).on(
			'click',
			'td.dbtn-lt-col-path',
			function (event) {
				const $td      = $( this );
				const path     = $td.attr( 'title' ) || $td.clone()
					.children( '.dbtn-lt-referer, .dbtn-lt-host-warning' ).remove().end()
					.text().trim();
				const referrer = $td.find( '.dbtn-lt-referer' ).first().text().trim();

				if ( ! path) {
					return;
				}

				const clipboardText = event.altKey ? path + '\n' + (referrer || 'no referrer') : path;

				copyText( clipboardText )
					.then( function () { showCopiedBadge( event.clientX, event.clientY ); } )
					.catch( function (error) { console.error( 'DBTN path clipboard error:', error ); } );
			}
		);

		function extractIp(text) {
			text = text.trim();

			const v4 = text.match( /(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/ );
			if (v4) {
				return v4[1];
			}

			const v6 = text.match( /([0-9a-fA-F]{1,4}(?::[0-9a-fA-F]{0,4}){2,})/ );
			if (v6) {
				return v6[1];
			}

			return text;
		}

		function classifyOrg(org) {
			if ( ! org) {
				return '';
			}

			const dc = /digital.?ocean|amazon|aws|google|microsoft|azure|\bapple\b|linode|vultr|ovh|hetzner|leaseweb|cloudflare|fastly|akamai|rackspace|choopa|psychz|quadranet|coloc|hosting|datacenter|data.center|server|vps|dedicated/i;

			return dc.test( org ) ? '🏢 Datacenter / Hosting' : '🏠 Residential / ISP';
		}

		$( document ).on(
			'click',
			'th.db_col_header.dbtn-lt-col-ip',
			function () {
				if (activeLogTab !== 'live') {
					return;
				}

				const $header = $( this );
				const $table  = $header.closest( '.dbtn-lt-table' );

				if ( ! $table.length || $table.hasClass( 'dbtn-lt-ip-traffic-table' )) {
					return;
				}

				if ( limitIP ) {
					limitIP = '';
					applyFilters();
					return;
				}

				if (ipHeaderSortActive) {
					resetLiveTrafficSorting();

					if (paused) {
						$pauseBtn.trigger( 'click' );
					} else {
						fetchTraffic();
					}

					return;
				}

				if ( ! paused) {
					$pauseBtn.trigger( 'click' );
				}

				const $tbody = $table.find( 'tbody' );
				const rows   = $tbody.find( 'tr' ).get();

				rows.sort(
					function (a, b) {
						const $aCell = $( a ).find( 'td.dbtn-lt-col-ip' );
						const $bCell = $( b ).find( 'td.dbtn-lt-col-ip' );
						const aIp    = extractIp( $aCell.attr( 'title' ) || $aCell.text() );
						const bIp    = extractIp( $bCell.attr( 'title' ) || $bCell.text() );

						return aIp.localeCompare( bIp, undefined, {
							numeric: true,
							sensitivity: 'base'
						} );
					}
				);

				$tbody.append( rows );
				pathHeaderSortActive = false;
				$content.find( 'th.db_col_header.dbtn-lt-col-path' )
					.text( 'Path' )
					.removeAttr( 'aria-sort' );
				uaHeaderSortActive = false;
				$content.find( 'th.db_col_header.dbtn-lt-col-ua' )
					.text( 'Browser / Bot' )
					.removeAttr( 'aria-sort' );
				geoHeaderSortActive = false;
				$content.find( 'th.db_col_header.dbtn-lt-col-geo' )
					.text( 'Location' )
					.removeAttr( 'aria-sort' );
				$header.text( 'IP-sorted' ).attr( 'aria-sort', 'ascending' );
				ipHeaderSortActive = true;
				updateIpHeader();
			}
		);

		$( document ).on(
			'click',
			'th.db_col_header.dbtn-lt-col-ua',
			function () {
				if (activeLogTab !== 'live') {
					return;
				}

				const $header = $( this );
				const $table  = $header.closest( '.dbtn-lt-table' );

				if ( ! $table.length || $table.hasClass( 'dbtn-lt-ip-traffic-table' )) {
					return;
				}

				if (uaHeaderSortActive) {
					resetLiveTrafficSorting();

					if (paused) {
						$pauseBtn.trigger( 'click' );
					} else {
						fetchTraffic();
					}

					return;
				}

				if ( ! paused) {
					$pauseBtn.trigger( 'click' );
				}

				const $tbody = $table.find( 'tbody' );
				const rows   = $tbody.find( 'tr' ).get();

				rows.sort(
					function (a, b) {
						const $aCell = $( a ).find( 'td.dbtn-lt-col-ua' );
						const $bCell = $( b ).find( 'td.dbtn-lt-col-ua' );
						const aUa    = $aCell.text().trim();
						const bUa    = $bCell.text().trim();

						return aUa.localeCompare( bUa, undefined, {
							numeric: true,
							sensitivity: 'base'
						} );
					}
				);

				$tbody.append( rows );
				ipHeaderSortActive   = false;
				pathHeaderSortActive = false;
				$content.find( 'th.db_col_header.dbtn-lt-col-ip' )
					.text( 'IP' )
					.removeAttr( 'aria-sort' );
				$content.find( 'th.db_col_header.dbtn-lt-col-path' )
					.text( 'Path' )
					.removeAttr( 'aria-sort' );
				geoHeaderSortActive = false;
				$content.find( 'th.db_col_header.dbtn-lt-col-geo' )
					.text( 'Location' )
					.removeAttr( 'aria-sort' );
				$header.text( 'Browser/Bot-sorted' ).attr( 'aria-sort', 'ascending' );
				uaHeaderSortActive = true;
			}
		);

		$( document ).on(
			'click',
			'th.db_col_header.dbtn-lt-col-geo',
			function () {
				if (activeLogTab !== 'live') {
					return;
				}

				const $header = $( this );
				const $table  = $header.closest( '.dbtn-lt-table' );

				if ( ! $table.length || $table.hasClass( 'dbtn-lt-ip-traffic-table' )) {
					return;
				}

				if (geoHeaderSortActive) {
					resetLiveTrafficSorting();

					if (paused) {
						$pauseBtn.trigger( 'click' );
					} else {
						fetchTraffic();
					}

					return;
				}

				if ( ! paused) {
					$pauseBtn.trigger( 'click' );
				}

				const $tbody = $table.find( 'tbody' );
				const rows   = $tbody.find( 'tr' ).get();

				rows.sort(
					function (a, b) {
						const $aCell = $( a ).find( 'td.dbtn-lt-col-geo' );
						const $bCell = $( b ).find( 'td.dbtn-lt-col-geo' );
						const aGeo   = $aCell.text().trim() || 'no location';
						const bGeo   = $bCell.text().trim() || 'no location';

						return aGeo.localeCompare( bGeo, undefined, {
							numeric: true,
							sensitivity: 'base'
						} );
					}
				);

				$tbody.append( rows );
				ipHeaderSortActive   = false;
				pathHeaderSortActive = false;
				uaHeaderSortActive   = false;
				$content.find( 'th.db_col_header.dbtn-lt-col-ip' )
					.text( 'IP' )
					.removeAttr( 'aria-sort' );
				$content.find( 'th.db_col_header.dbtn-lt-col-path' )
					.text( 'Path' )
					.removeAttr( 'aria-sort' );
				$content.find( 'th.db_col_header.dbtn-lt-col-ua' )
					.text( 'Browser / Bot' )
					.removeAttr( 'aria-sort' );
				$header.text( 'Location-sorted' ).attr( 'aria-sort', 'ascending' );
				geoHeaderSortActive = true;
			}
		);

		$( document ).on(
			'click',
			'td.dbtn-lt-col-ip',
			function (e) {
				const $td = $( this );

				if (
				$( e.target ).hasClass( 'dbtn-lt-ip-close' ) ||
				$( e.target ).closest( '.dbtn-lt-ip-card' ).length
				) {
					return;
				}

				if (e.altKey) {
					const rawText  = $td.attr( 'title' ) || $td.text();
					const clickedIP = extractIp( rawText );

					if ( ! clickedIP) {
						return;
					}

					limitIP = limitIP === clickedIP ? '' : clickedIP;
					applyFilters();
					return;
				}

				$td.find( '.dbtn-lt-ip-card' ).remove();

				const rawText = $td.clone().children( '.dbtn-lt-ip-card' ).remove().end().text().trim();
				const ip      = extractIp( rawText );

				if ( ! ip) {
					return;
				}

				if (navigator.clipboard) {
					navigator.clipboard.writeText( ip ).catch( function () {} );
				}

				const escapedIp = $( '<span>' ).text( ip ).html();
				const $card     = $(
					'<div class="dbtn-lt-ip-card">' +
					'<button class="dbtn-lt-ip-close" title="Close">✕</button>' +
					'<div class="dbtn-lt-ip-card-body">Looking up <strong>' +
					escapedIp +
					'</strong>…</div>' +
					'<div class="dbtn-lt-ip-card-actions"><button type="button" class="button button-small dbtn-lt-show-ip-traffic" data-ip="' +
					escapedIp +
					'">Show IP traffic</button></div>' +
					'</div>'
				);

				$td.append( $card );

				const pausedByCard = activeLogTab === 'live' && ! paused;

				if (pausedByCard) {
					$pauseBtn.trigger( 'click' );
				}

				$card.find( '.dbtn-lt-ip-close' ).on(
					'click',
					function (event) {
						event.stopPropagation();
						$card.remove();

						if (pausedByCard && paused && activeLogTab === 'live') {
							$pauseBtn.trigger( 'click' );
						}
					}
				);

				$card.find( '.dbtn-lt-show-ip-traffic' ).on(
					'click',
					function (event) {
						event.preventDefault();
						event.stopPropagation();
						showIpTraffic( ip );
					}
				);

				fetch(
					'https://ipinfo.io/' + encodeURIComponent( ip ) + '/json',
					{
						headers: {
							Accept: 'application/json'
						}
					}
				)
				.then(
					function (response) {
						return response.json();
					}
				)
				.then(
					function (data) {
						const org      = data.org || '';
						const hostname = data.hostname || '';
						const city     = data.city || '';
						const region   = data.region || '';
						const country  = data.country || '';
						const type     = classifyOrg( org );
						const location = [city, region, country].filter( Boolean ).join( ', ' );

						let html = '<strong>' + escapedIp + '</strong>';
						html    += ' <span class="dbtn-lt-ip-copied">(copied)</span><br>';

						if (type) {
							html += type + '<br>';
						}

						if (org) {
							html += $( '<span>' ).text( org ).html() + '<br>';
						}

						if (location) {
							html += '📍 ' + $( '<span>' ).text( location ).html() + '<br>';
						}

						if (hostname) {
							html += '🌐 ' + $( '<span>' ).text( hostname ).html();
						}

						$card.find( '.dbtn-lt-ip-card-body' ).html( html );
					}
				)
				.catch(
					function () {
						$card.find( '.dbtn-lt-ip-card-body' ).html(
							'Lookup failed for <strong>' + escapedIp + '</strong>'
						);
					}
				);
			}
		);
	}
);
