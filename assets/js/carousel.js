/**
 * Frontend script for the courses carousel block (kouib/courses-carousel).
 * Vanilla JS — supports touch dragging, autoplay, infinite looping,
 * dots and arrows, RTL/LTR, and re-initialization in the Gutenberg editor.
 */
(function() {
	function kouibCarouselInit() {
		var wrappers = document.querySelectorAll(".kouib-carousel-wrapper");
		if (!wrappers.length) return;

		wrappers.forEach(function(wrapper) {
			if (wrapper.getAttribute("data-kouibc-ready") === "1") return;
			wrapper.setAttribute("data-kouibc-ready", "1");

			var viewport = wrapper.querySelector(".kouib-carousel-viewport");
			var track    = wrapper.querySelector(".kouib-carousel-track");
			if (!viewport || !track) return;

			var slides = Array.prototype.slice.call(track.children);
			var realN  = slides.length;
			if (!realN) return;

			var d = wrapper.dataset;
			var config = {
				autoplay:      d.kouibcAutoplay === "1",
				autoplaySpeed: parseInt(d.kouibcAutoplaySpeed, 10) || 3000,
				speed:         parseInt(d.kouibcSpeed, 10) || 500,
				infinite:      d.kouibcInfinite === "1",
				dots:          d.kouibcDots === "1",
				hoverPause:    d.kouibcHoverPause === "1",
				columns:       parseInt(d.kouibcColumns, 10) || 3,
				colsTablet:    parseInt(d.kouibcColsTablet, 10) || 2,
				colsMobile:    parseInt(d.kouibcColsMobile, 10) || 1,
				rtl:           wrapper.getAttribute("dir") === "rtl"
			};

			// Forces the number of columns based on the viewport width (inline computed style overrides all rules)
			function applyColumnsByViewport() {
				var w = window.innerWidth || document.documentElement.clientWidth;
				var c = w <= 600 ? config.colsMobile : (w <= 992 ? config.colsTablet : config.columns);
				wrapper.style.setProperty("--kouib-carousel-columns", String(c));
			}

			var sign        = config.rtl ? 1 : -1;
			var infinite    = config.infinite && realN > config.columns;
			var pos         = 0;
			var base        = 0;
			var maxReal     = 0;
			var canMove     = realN > 1;
			var busy        = false;
			var step        = 0;
			var timer       = null;
			var startX      = 0;
			var lastX       = 0;
			var baseOffsetX = 0;

			// The actual number of displayed columns (read from the responsive CSS, so it changes per device)
			function currentCols() {
				var cs = window.getComputedStyle(wrapper);
				var v = parseInt(cs.getPropertyValue("--kouib-carousel-columns"), 10);
				return v > 0 ? v : config.columns;
			}

			// Recomputes the boundaries based on the actual column count (called at init and on resize)
			function recompute() {
				var c = currentCols();
				if (infinite) {
					// base = start position of the first real cycle in the stream = the number of appended copies
					// (config.columns is constant). We don't use c here because the copies are not rebuilt
					// when the screen changes; if base used the device column count, the starting
					// point would land on a copy from a later cycle and break the loop seam.
					base = config.columns;
					maxReal = Math.max(0, realN - c);
				} else {
					base = 0;
					maxReal = realN > c ? realN - c : Math.max(0, realN - 1);
				}
				canMove = realN > 1;
				if (pos < base) pos = base;
				if (pos > base + maxReal) pos = base + maxReal;
			}

			// Builds extra copies for the infinite loop: the last C at the front, and the first C at the back.
			if (infinite) {
				var append = slides.slice(0, config.columns).map(copyNode);
				var prepend = slides.slice(realN - config.columns).map(copyNode);
				prepend.forEach(function(c) { track.insertBefore(c, slides[0]); });
				append.forEach(function(c) { track.appendChild(c); });
				slides = Array.prototype.slice.call(track.children);
			}

			var prevBtn  = wrapper.querySelector(".kouib-carousel-prev");
			var nextBtn  = wrapper.querySelector(".kouib-carousel-next");
			var dotsWrap = wrapper.querySelector(".kouib-carousel-dots");

			applyColumnsByViewport();
			recompute();

			function copyNode(node) {
				return node.cloneNode(true);
			}

			function gap() {
				var cs = window.getComputedStyle(wrapper);
				return parseFloat(cs.getPropertyValue("--kouib-carousel-gap")) || 0;
			}

			function measure() {
				if (slides[0]) {
					step = slides[0].getBoundingClientRect().width + gap();
				}
			}

			function offsetFor(p) {
				return sign * p * step;
			}

			function render(animate) {
				if (animate) {
					track.style.transition = config.speed + "ms ease";
				} else {
					track.style.transition = "none";
				}
				track.style.transform = "translateX(" + offsetFor(pos) + "px)";
				updateChrome();
			}

			function dotIndex() {
				return ((pos - base) % realN + realN) % realN;
			}

			function updateChrome() {
				if (dotsWrap) {
					var dots = dotsWrap.children;
					var active = dotIndex();
					for (var i = 0; i < dots.length; i++) {
						dots[i].classList.toggle("active", i === active);
					}
				}
				if (canMove && !infinite && prevBtn) {
					prevBtn.disabled = pos <= 0;
					nextBtn.disabled = pos >= maxReal;
				}
			}

			function buildDots() {
				if (!dotsWrap) return;
				dotsWrap.innerHTML = "";
				for (var i = 0; i < realN; i++) {
					var b = document.createElement("button");
					b.type = "button";
					b.className = "kouib-carousel-dot";
					b.setAttribute("aria-label", (i + 1) + " / " + realN);
					b.addEventListener("click", function(idx) {
						return function() { goTo(idx); };
					}(i));
					dotsWrap.appendChild(b);
				}
			}

			function go(delta) {
				var target = pos + delta;

				if (infinite) {
					// Infinite loop: at either end we jump without animation to the
					// exact matching position (a visual twin) so it appears continuous with no visible jump.
					if (target > base + realN) {
						pos = base;
						render(false);
					} else if (target < 0) {
						pos = base + maxReal;
						render(false);
					} else {
						pos = target;
						render(true);
					}
				} else {
					if (target < 0) target = 0;
					if (target > maxReal) target = maxReal;
					pos = target;
					render(true);
				}
				resetAutoplay();
			}

			function goTo(realIndex) {
				if (infinite) {
					// In infinite mode every real cycle is reachable:
					// positions before/beyond the end are expressed through the loop clones.
					if (realIndex < 0) realIndex = 0;
					if (realIndex > realN - 1) realIndex = realN - 1;
				} else {
					if (realIndex < 0 || realIndex > maxReal) realIndex = pos - base;
				}
				pos = base + realIndex;
				render(true);
				resetAutoplay();
			}

			function startAutoplay() {
				stopAutoplay();
				if (!config.autoplay || !canMove) return;
				timer = setInterval(function() {
					if (!busy && !dragActive) go(1);
				}, config.autoplaySpeed);
			}

			function stopAutoplay() {
				if (timer) { clearInterval(timer); timer = null; }
			}

			function resetAutoplay() {
				startAutoplay();
			}

			// Start/stop autoplay on mouse hover
			if (config.autoplay && config.hoverPause) {
				wrapper.addEventListener("mouseenter", stopAutoplay);
				wrapper.addEventListener("mouseleave", startAutoplay);
			}

			if (prevBtn) {
				prevBtn.addEventListener("click", function() { go(-1); });
			}
			if (nextBtn) {
				nextBtn.addEventListener("click", function() { go(1); });
			}

			// Prevent clicks on links right after a drag ends (so a link is not opened by mistake)
			var suppressClick = false;
			wrapper.addEventListener("click", function(e) {
				if (suppressClick) {
					e.preventDefault();
					e.stopPropagation();
					suppressClick = false;
				}
			}, true);

			// Dragging via mouse, touch, and pen (Pointer Events — works on all
			// modern browsers, with a touch fallback for older devices).
			//
			// Note: we never use setPointerCapture here — because capture redirects
			// mouse and click events to the carousel itself, keeping the mouse "stuck"
			// to it so card links never open. Instead we track pointer movement at the
			// document level only while dragging and release it immediately on release.
			// A stationary click (<15px of movement) stays a normal click delivered
			// to the link without any interference.
			viewport.style.touchAction = "pan-y";

			var usingPointer = typeof window.PointerEvent !== "undefined";

			var dragActivation = 15;
			var dragActive = false;

			function getDragX(e) {
				if (e.touches && e.touches.length) return e.touches[0].clientX;
				if (e.changedTouches && e.changedTouches.length) return e.changedTouches[0].clientX;
				return e.clientX;
			}

			function onDragMoveDocument(e) {
				var cx = getDragX(e);
				lastX = cx;
				if (!dragActive && Math.abs(cx - startX) >= dragActivation) {
					dragActive = true;
					wrapper.classList.add("kouibc-dragging");
				}
				if (dragActive) {
					track.style.transition = "none";
					track.style.transform = "translateX(" + (baseOffsetX + (cx - startX)) + "px)";
				}
			}

			function onDragEnd(e) {
				document.removeEventListener("pointermove", onDragMoveDocument);
				document.removeEventListener("pointerup", onDragEnd);
				document.removeEventListener("pointercancel", onDragEnd);
				document.removeEventListener("touchmove", onDragMoveDocument);
				document.removeEventListener("touchend", onDragEnd);
				document.removeEventListener("touchcancel", onDragEnd);

				if (dragActive) {
					dragActive = false;
					wrapper.classList.remove("kouibc-dragging");
					var dx = (e.changedTouches && e.changedTouches.length)
						? e.changedTouches[0].clientX - startX
						: (lastX - startX);
					var threshold = Math.min(80, step * 0.25);
					if (Math.abs(dx) >= threshold && Math.abs(dx) > 10) {
						var forward = (dx * sign) > 0;
						suppressClick = true;
						go(forward ? 1 : -1);
					} else {
						render(true);
						resetAutoplay();
					}
				} else {
					resetAutoplay();
				}
			}

			function onDragStart(e) {
				if (!canMove) return;
				if (usingPointer && e.pointerType === "mouse" && e.button !== 0) return;
				busy = false;
				dragActive = false;
				startX = getDragX(e);
				lastX = startX;
				baseOffsetX = offsetFor(pos);
				stopAutoplay();

				if (usingPointer) {
					document.addEventListener("pointermove", onDragMoveDocument);
					document.addEventListener("pointerup", onDragEnd);
					document.addEventListener("pointercancel", onDragEnd);
				} else {
					document.addEventListener("touchmove", onDragMoveDocument, { passive: false });
					document.addEventListener("touchend", onDragEnd);
					document.addEventListener("touchcancel", onDragEnd);
				}
			}

			if (usingPointer) {
				viewport.addEventListener("pointerdown", onDragStart);
			} else {
				viewport.addEventListener("touchstart", onDragStart, { passive: true });
			}

			// Re-measures on window resize (responsive layout)
			var resizeTimer = null;
			window.addEventListener("resize", function() {
				if (resizeTimer) clearTimeout(resizeTimer);
				resizeTimer = setTimeout(function() {
					applyColumnsByViewport();
					recompute();
					measure();
					render(false);
					startAutoplay();
				}, 150);
			});

			// Re-measures after images load (because card height/width may change)
			if (window.Image && slides.length) {
				for (var i = 0; i < slides.length; i++) {
					var imgs = slides[i].querySelectorAll("img");
					for (var j = 0; j < imgs.length; j++) {
						if (!imgs[j].complete) {
							imgs[j].addEventListener("load", function() {
								measure();
								render(false);
							}, { once: true });
						}
					}
				}
			}

			measure();
			if (!canMove) {
				wrapper.classList.add("kouibc-static");
			}
			if (dotsWrap && canMove) {
				buildDots();
			}
			render(false);
			startAutoplay();
		});
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", kouibCarouselInit);
	} else {
		kouibCarouselInit();
	}
	setTimeout(kouibCarouselInit, 400);
	setTimeout(kouibCarouselInit, 1200);

	if (document.body && document.body.classList.contains("block-editor-page") && typeof MutationObserver !== "undefined") {
		var observer = new MutationObserver(function() {
			setTimeout(kouibCarouselInit, 200);
		});
		observer.observe(document.body, { childList: true, subtree: true });
	}
})();