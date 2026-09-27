'use strict';

// Načítá se v <head> ještě před styly, aby světlý vzhled neprobliknul tmavým.
(function applyStoredTheme() {
  try {
    const theme = localStorage.getItem('td-theme');
    if (theme === 'light' || theme === 'dark') document.documentElement.dataset.theme = theme;
  } catch (error) {
    // Bez přístupu k úložišti zůstane výchozí tmavý vzhled.
  }
})();
