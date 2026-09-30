<?php
/**
 * email_quotes.php — strip the email quote markers ("> " at the start of a line)
 * from pasted client emails, in the browser, before the form is posted.
 *
 * The server firewall (ModSecurity) answers 406 "Not Acceptable" to a post with a
 * line starting with ">" after another ">" line (">\n> text"), the usual layout
 * of a quoted email reply — so it never reaches the Hub and cannot be cleaned
 * there. Only ">" at the start of a line are removed ("> ", ">>", "  >");
 * a ">" inside the text ("Arusha > Serengeti", "budget > 3000") stays.
 *
 * Usage: add data-strip-quotes to the textareas, then include this file once
 * after the form. They are cleaned when text is pasted (so the user sees what is
 * saved) and again when their form is submitted.
 */
?>
<script>
(function () {
  function stripEmailQuotes(s) {
    return String(s).replace(/^[ \t]*(?:>[ \t]?)+/gm, '');
  }
  window.stripEmailQuotes = stripEmailQuotes;
  document.querySelectorAll('textarea[data-strip-quotes]').forEach(function (t) {
    t.addEventListener('paste', function () {
      setTimeout(function () {
        var v = stripEmailQuotes(t.value);
        if (v !== t.value) t.value = v;
      }, 0);
    });
    if (t.form && !t.form.dataset.stripQuotes) {
      t.form.dataset.stripQuotes = '1';
      t.form.addEventListener('submit', function () {
        t.form.querySelectorAll('textarea[data-strip-quotes]').forEach(function (x) {
          if (!x.disabled) x.value = stripEmailQuotes(x.value);
        });
      });
    }
  });
})();
</script>
