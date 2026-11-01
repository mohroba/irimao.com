(function () {
  "use strict";
  document.querySelectorAll("[data-imao-bracket]").forEach(function (root) {
    if (root.dataset.ready) return;
    root.dataset.ready = "1";
    var view = root.querySelector(".imao-bracket-viewport");
    var canvas = root.querySelector(".imao-bracket-canvas");
    var tree = root.querySelector(".imao-bracket-tree");
    var scale = 1;
    var drag = null;
    var frame = null;
    var savedScale = 1;
    var activeProvince = null;
    function zoom(value) {
      scale = Math.max(0.15, Math.min(2, value));
      tree.style.transform = "scale(" + scale + ")";
      canvas.style.width = Math.ceil(tree.offsetWidth * scale) + "px";
      canvas.style.height = Math.ceil(tree.offsetHeight * scale) + "px";
    }
    function fit() {
      zoom(Math.min(1, (view.clientWidth - 40) / tree.offsetWidth));
    }
    function draw() {
      frame = null;
      var previous = tree.querySelector(".imao-bracket-connectors");
      if (previous) previous.remove();
      var ns = "http://www.w3.org/2000/svg";
      var svg = document.createElementNS(ns, "svg");
      svg.setAttribute("class", "imao-bracket-connectors");
      svg.setAttribute("aria-hidden", "true");
      svg.setAttribute("width", tree.offsetWidth);
      svg.setAttribute("height", tree.offsetHeight);
      var bounds = tree.getBoundingClientRect();
      var ratio = bounds.width / tree.offsetWidth || 1;
      function connect(source, target) {
        var a = source.getBoundingClientRect();
        var b = target.getBoundingClientRect();
        var x1 = (a.right - bounds.left) / ratio;
        var y1 = (a.top + a.height / 2 - bounds.top) / ratio;
        var x2 = (b.left - bounds.left) / ratio;
        var y2 = (b.top + b.height / 2 - bounds.top) / ratio;
        var middle = x1 + (x2 - x1) / 2;
        var path = document.createElementNS(ns, "path");
        path.setAttribute(
          "d",
          "M" + x1 + " " + y1 + " H" + middle + " V" + y2 + " H" + x2,
        );
        svg.appendChild(path);
      }
      var rounds = tree.querySelectorAll(".imao-bracket-round");
      rounds.forEach(function (round, index) {
        if (index === rounds.length - 1) return;
        var parents = rounds[index + 1].querySelectorAll(".imao-bracket-match");
        round
          .querySelectorAll(".imao-bracket-match")
          .forEach(function (match, matchIndex) {
            var parent = parents[Math.floor(matchIndex / 2)];
            if (parent)
              connect(
                match,
                parent.querySelector(
                  '[data-side="' + (matchIndex % 2 === 0 ? "a" : "b") + '"]',
                ),
              );
          });
      });
      var finalMatch = tree.querySelector(".imao-bracket-final");
      var champion = tree.querySelector(".imao-bracket-champion");
      if (finalMatch && champion) connect(finalMatch, champion);
      tree.insertBefore(svg, tree.firstChild);
      zoom(scale);
    }
    function schedule() {
      if (frame === null) frame = window.requestAnimationFrame(draw);
    }
    root.addEventListener("click", function (event) {
      var button = event.target.closest("button");
      if (!button || !root.contains(button)) return;
      if (button.dataset.provinceFilter) {
        activeProvince =
          activeProvince === button.dataset.provinceFilter
            ? null
            : button.dataset.provinceFilter;
        root
          .querySelectorAll(".imao-bracket-player[data-province]")
          .forEach(function (player) {
            player.classList.toggle(
              "is-muted",
              activeProvince !== null &&
                player.dataset.province !== activeProvince,
            );
          });
        root
          .querySelectorAll("[data-province-filter]")
          .forEach(function (item) {
            item.setAttribute(
              "aria-pressed",
              item.dataset.provinceFilter === activeProvince ? "true" : "false",
            );
          });
        return;
      }
      if (button.hasAttribute("data-print")) {
        window.print();
        return;
      }
      var action = button.dataset.zoom;
      if (action === "fit") fit();
      else if (action === "reset") zoom(1);
      else if (action) zoom(scale + (action === "in" ? 0.15 : -0.15));
    });
    view.addEventListener("pointerdown", function (event) {
      if (event.pointerType !== "mouse" || event.button !== 0) return;
      drag = {
        x: event.clientX,
        y: event.clientY,
        left: view.scrollLeft,
        top: view.scrollTop,
      };
      view.setPointerCapture(event.pointerId);
      view.classList.add("is-dragging");
    });
    view.addEventListener("pointermove", function (event) {
      if (!drag) return;
      view.scrollLeft = drag.left - (event.clientX - drag.x);
      view.scrollTop = drag.top - (event.clientY - drag.y);
    });
    function endDrag() {
      drag = null;
      view.classList.remove("is-dragging");
    }
    view.addEventListener("pointerup", endDrag);
    view.addEventListener("pointercancel", endDrag);
    view.addEventListener(
      "wheel",
      function (event) {
        if (!event.ctrlKey) return;
        event.preventDefault();
        zoom(scale + (event.deltaY < 0 ? 0.1 : -0.1));
      },
      { passive: false },
    );
    if (window.ResizeObserver) new ResizeObserver(schedule).observe(view);
    window.addEventListener("beforeprint", function () {
      savedScale = scale;
      fit();
    });
    window.addEventListener("afterprint", function () {
      zoom(savedScale);
    });
    if (document.fonts && document.fonts.ready)
      document.fonts.ready.then(schedule);
    schedule();
  });
})();
