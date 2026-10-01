(function () {
	'use strict';

	function activateTab(targetId) {
		var tabs = document.querySelectorAll('.wpi-tabs .nav-tab');
		var panels = document.querySelectorAll('.wpi-tab-panel');

		tabs.forEach(function (tab) {
			tab.classList.toggle('nav-tab-active', tab.getAttribute('href') === targetId);
		});

		panels.forEach(function (panel) {
			panel.hidden = '#' + panel.id !== targetId;
		});
	}

	document.addEventListener('click', function (event) {
		var tab = event.target.closest('.wpi-tabs .nav-tab');

		if (!tab) {
			return;
		}

		event.preventDefault();
		window.location.hash = tab.getAttribute('href');
		activateTab(tab.getAttribute('href'));
	});

	document.addEventListener('DOMContentLoaded', function () {
		var targetId = window.location.hash || '#wpi-tab-plan';

		if (!document.querySelector(targetId)) {
			targetId = '#wpi-tab-plan';
		}

		activateTab(targetId);
	});
}());

