/**
 * Frontend script for the Tutor LMS courses filter block.
 * Loaded via viewScript in block.json on the frontend, and via enqueue_block_editor_assets
 * in the editor (so the filter buttons work in the ServerSideRender preview).
 */
(function() {
	function initKouib() {
		var wrappers = document.querySelectorAll(".tutor-courses-filter-wrapper");
		if (!wrappers.length) return;

		wrappers.forEach(function(wrapper) {
			if (wrapper.getAttribute("data-kouib-ready") === "1") return;
			wrapper.setAttribute("data-kouib-ready", "1");

			var buttons = wrapper.querySelectorAll(".kouib-btn");
			var lists   = wrapper.querySelector(".tutor-courses-filter-lists");
			if (!lists) return;
			var loading = null;

			function buildUrl(term) {
				var d = wrapper.dataset;
				var url = d.kouibsRest + "?term=" + encodeURIComponent(term)
					+ "&orderby=" + encodeURIComponent(d.kouibsOrderby || "date")
					+ "&perPage=" + encodeURIComponent(d.kouibsPerpage || 3);
				["showLevel","showRating","showLessons","showDuration","showPrice","showStudents","showEnrollBtn","enrollBtnText","openInNewTab"].forEach(function(k) {
					var attr = "kouib" + k.charAt(0).toUpperCase() + k.slice(1).toLowerCase();
					var v = d[attr];
					if (v !== undefined && v !== null && v !== "") url += "&" + k + "=" + encodeURIComponent(v);
				});
				return url;
			}

			function activate(term) {
				buttons.forEach(function(b) {
					b.classList.toggle("active", b.getAttribute("data-term") === term);
				});
				wrapper.querySelectorAll(".kouib-courses-grid").forEach(function(g) {
					var m = term === "all"
						? g.classList.contains("kouib-all")
						: g.classList.contains("kouib-" + term);
					g.classList.toggle("active", m);
				});
			}

			function skeletonHtml(count) {
				count = count || 3;
				var cards = "";
				for (var i = 0; i < count; i++) {
					cards +=
						'<div class="kouib-skeleton-card">' +
							'<div class="kouib-skeleton-thumb"></div>' +
							'<div class="kouib-skeleton-body">' +
								'<div class="kouib-skeleton-line title"></div>' +
								'<div class="kouib-skeleton-line short"></div>' +
								'<div class="kouib-skeleton-line short"></div>' +
								'<div class="kouib-skeleton-line btn"></div>' +
							'</div>' +
						'</div>';
				}
				return '<div class="kouib-skeleton-row">' + cards + '</div>';
			}

			function loadTerm(term) {
				if (lists.querySelector(".kouib-courses-grid.kouib-" + term)) {
					activate(term);
					return;
				}
				if (loading) return;
				loading = term;

				var skelShown = false;
				var skel = null;

				// Short delay to avoid skeleton flash on fast connections
				var timer = setTimeout(function() {
					if (skelShown) return;
					skelShown = true;
					skel = document.createElement("div");
					skel.className = "kouib-courses-grid kouib-" + term + " active kouib-is-skeleton";
					skel.setAttribute("data-kouib-page", "1");
					skel.innerHTML = skeletonHtml(parseInt(wrapper.dataset.kouibsPerpage, 10) || 3);
					lists.insertBefore(skel, lists.firstChild);

					wrapper.querySelectorAll(".kouib-courses-grid").forEach(function(g) {
						if (g !== skel) g.classList.remove("active");
					});
				}, 250);

				fetch(buildUrl(term))
					.then(function(r) { return r.json(); })
					.then(function(data) {
						clearTimeout(timer);
						if (data && data.html) {
							if (!skelShown) {
								skel = document.createElement("div");
								skel.className = "kouib-courses-grid kouib-" + term;
								skel.setAttribute("data-kouib-page", "1");
								skel.innerHTML = data.html;
								lists.insertBefore(skel, lists.firstChild);
							} else {
								skel.classList.remove("kouib-is-skeleton");
								skel.innerHTML = data.html;
							}
							activate(term);
						} else if (skel) {
							skel.remove();
						}
					})
					.catch(function() { clearTimeout(timer); if (skel) { skel.remove(); } })
					.finally(function() { loading = null; });
			}

			function loadMore(term, btn) {
				if (loading) return;
				loading = term;

				var grid = lists.querySelector(".kouib-courses-grid.kouib-" + term);
				if (!grid) { loading = null; return; }

				var page = (parseInt(grid.getAttribute("data-kouib-page"), 10) || 1) + 1;
				var moreWrap = grid.querySelector(".kouib-more-wrap");
				var label = "Load more";
				if (btn) { label = btn.textContent; btn.disabled = true; btn.textContent = "..."; }

				fetch(buildUrl(term) + "&page=" + page)
					.then(function(r) { return r.json(); })
					.then(function(data) {
						if (data && data.html && !(data.html.indexOf("kouib-row") === -1 && !data.hasMore)) {
							var tmp = document.createElement("div");
							tmp.innerHTML = data.html;
							while (tmp.firstChild) {
								grid.insertBefore(tmp.firstChild, moreWrap || null);
							}
							grid.setAttribute("data-kouib-page", page);
							if (!data.hasMore && moreWrap) { moreWrap.remove(); return; }
							if (btn) { btn.disabled = false; btn.textContent = label; }
						} else {
							if (moreWrap) moreWrap.remove();
						}
					})
					.catch(function() { if (btn) { btn.disabled = false; btn.textContent = label; } })
					.finally(function() { loading = null; });
			}

			var current = null;
			buttons.forEach(function(b) { if (b.classList.contains("active")) current = b.getAttribute("data-term"); });
			if (!current && buttons.length > 0) current = buttons[0].getAttribute("data-term");
			if (current) activate(current);

			wrapper.addEventListener("click", function(e) {
				var lm = e.target.closest(".kouib-load-more-btn");
				if (lm) {
					e.preventDefault();
					var gridEl = lm.closest(".kouib-courses-grid");
					var term = "all";
					if (gridEl && !gridEl.classList.contains("kouib-all")) {
						var m = gridEl.className.match(/kouib-term-(\d+)/);
						if (m) term = "term-" + m[1];
					}
					loadMore(term, lm);
					return;
				}
				var btn = e.target.closest(".kouib-btn");
				if (!btn) return;
				e.preventDefault();
				var term = btn.getAttribute("data-term");
				if (!term) return;
				if (term === "all" || lists.querySelector(".kouib-courses-grid.kouib-" + term)) {
					activate(term);
				} else {
					loadTerm(term);
				}
			});
		});
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", initKouib);
	} else {
		initKouib();
	}
	setTimeout(initKouib, 400);
	setTimeout(initKouib, 1200);

	if (document.body.classList.contains("block-editor-page") && typeof MutationObserver !== "undefined") {
		var observer = new MutationObserver(function() {
			setTimeout(initKouib, 200);
		});
		observer.observe(document.body, { childList: true, subtree: true });
	}
})();
