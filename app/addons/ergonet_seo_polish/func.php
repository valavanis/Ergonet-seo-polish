<?php
declare(strict_types=1);

use Tygh\Registry;

defined('BOOTSTRAP') or die('Access denied');

/**
 * Δύο ευρήματα SEO του Lighthouse που τα γεννά ο πυρήνας του CS-Cart.
 *
 * ΜΕΤΡΗΘΗΚΕ 02/09/2026 σε σελίδα προϊόντος:
 *   crawlable-anchors  ✗  29 από 566 anchors ΧΩΡΙΣ href
 *   link-text          ✗  2 links χωρίς προσβάσιμο όνομα
 *
 * Και τα δύο είναι **pass/fail**: ένα μόνο σφάλμα ρίχνει ολόκληρο τον έλεγχο. Δεν
 * υπάρχει μερική βελτίωση — ή διορθώνονται όλα ή ο βαθμός δεν κουνιέται.
 *
 * ΤΑ ANCHORS ΕΙΝΑΙ ΚΟΥΜΠΙΑ. Ο πυρήνας γράφει `<a class="cm-submit" data-ca-dispatch=…>`
 * για «προσθήκη στο καλάθι», «λίστα επιθυμιών», καρτέλες, +/− ποσότητας, dialogs.
 * Δεν πλοηγούν πουθενά — δεν είναι σύνδεσμοι. Το `role="button"` είναι η **σωστή**
 * σημασιολογική δήλωση όταν δεν μπορείς να αλλάξεις το tag, όχι τέχνασμα για το
 * εργαλείο: το ίδιο πράγμα λέει και στους αναγνώστες οθόνης. Ο έλεγχος
 * crawlable-anchors παραλείπει ρητά όποιο anchor έχει μη-κενό `role`.
 *
 * ΓΙΑΤΙ OUTPUT FILTER ΚΑΙ ΟΧΙ OVERRIDES: τα 29 anchors βγαίνουν από 6 διαφορετικά
 * core templates. Έξι overrides στο παιδικό θέμα σημαίνει έξι αντίγραφα που παγώνουν
 * — κάθε διόρθωση που φέρνει μελλοντική αναβάθμιση CS-Cart θα λείπει σιωπηλά από
 * όλα. Ένα φίλτρο, ένα σημείο συντήρησης.
 *
 * ΓΙΑΤΙ ΟΧΙ JS: το Lighthouse διαβάζει το DOM μετά την εκτέλεση, οπότε JS θα
 * «δούλευε». Αλλά τότε η προσβασιμότητα εξαρτάται από JavaScript, που είναι ακριβώς
 * το αντίθετο από το νόημά της.
 */

function fn_ergonet_seo_polish_dispatch_before_display(): void
{
    if (AREA !== 'C' || defined('AJAX_REQUEST')) {
        return;
    }
    if (Registry::get('addons.ergonet_seo_polish.enabled') !== 'Y') {
        return;
    }
    static $registered = false;
    if ($registered) {
        return;
    }
    $view = Tygh::$app['view'];
    if (!is_object($view) || !method_exists($view, 'registerFilter')) {
        return;
    }
    $registered = true;
    $view->registerFilter('output', 'fn_ergonet_seo_polish_rewrite');
}

/**
 * @param string $html
 * @return string
 */
function fn_ergonet_seo_polish_rewrite(string $html): string
{
    if (stripos($html, '</head>') === false) {
        return $html;
    }

    // ── 1. anchors χωρίς href → role="button" ────────────────────────────────
    //
    // Το μοτίβο είναι ΣΤΕΝΟ κατά πρόθεση: πιάνει `<a` + attributes που ΔΕΝ περιέχουν
    // ούτε `href=` ούτε `role=`, μέχρι το πρώτο `>`. Το `[^>]*` δεν μπορεί να
    // περάσει έξω από το tag, άρα δεν μπορεί να «καταπιεί» δομή.
    //
    // Η αρνητική αναζήτηση γίνεται με lookahead στο ΣΥΝΟΛΟ των attributes, όχι
    // ανά χαρακτήρα: `(?![^>]*\shref=)` σημαίνει «πουθενά μέσα σε αυτό το tag».
    $html = preg_replace(
        '~<a(?![^>]*\shref=)(?![^>]*\srole=)(\s[^>]*)?>~i',
        '<a role="button" tabindex="0"$1>',
        $html
    );

    // ── 1β. κρυφά template stubs με href="" → role="presentation" ───────────
    //
    // Η σελιδοποίηση του πυρήνα αφήνει στο DOM ένα `<a href="" class="hidden"
    // aria-hidden="true">` ως πρότυπο για το AJAX. Δεν είναι διαδραστικό —
    // το `aria-hidden` το λέει ήδη — αλλά ο έλεγχος crawlable-anchors κοιτά μόνο
    // href και role. Το `role="presentation"` είναι η ακριβής δήλωση: «αυτό δεν
    // είναι στοιχείο διεπαφής».
    //
    // ΣΤΕΝΟ ΜΟΤΙΒΟ: απαιτεί ΚΑΙ κενό href ΚΑΙ aria-hidden="true". Ένα anchor με
    // κενό href που ΔΕΝ είναι aria-hidden είναι πραγματικό σφάλμα και πρέπει να
    // παραμείνει ορατό στον έλεγχο, όχι να κρυφτεί από αυτό το φίλτρο.
    $out = preg_replace(
        '~<a(?![^>]*\srole=)((?=[^>]*\shref="")(?=[^>]*\saria-hidden="true")[^>]*)>~i',
        '<a role="presentation"$1>',
        $html
    );
    if ($out !== null) {
        $html = $out;
    }

    // ── 2. links με μόνο εικονίδιο → aria-label ──────────────────────────────
    //
    // Το εικονίδιο είναι `aria-hidden="true"`, οπότε το link δεν έχει ΚΑΝΕΝΑ
    // προσβάσιμο όνομα — ούτε για το Lighthouse ούτε για τυφλό χρήστη. Δύο
    // περιπτώσεις στον πυρήνα, και οι δύο σταθερές, οπότε στοχευμένη διόρθωση
    // αντί για γενικό κανόνα που θα μάντευε ετικέτες.
    $labels = [
        // «Ο λογαριασμός μου» — εικονίδιο χρήστη στην κεφαλίδα
        '~(<a\s[^>]*class="[^"]*ty-account-info__title[^"]*"[^>]*)(>)~i'
            => '$1 aria-label="' . __('my_account') . '"$2',
        // Το «?» δίπλα σε πεδία — άνοιγμα βοηθητικού παραθύρου
        '~(<a\s[^>]*class="[^"]*cm-dialog-opener[^"]*"(?![^>]*aria-label)[^>]*)(>)~i'
            => '$1 aria-label="' . __('ergonet_seo_polish.help') . '"$2',
    ];
    foreach ($labels as $pattern => $replacement) {
        $out = preg_replace($pattern, $replacement, $html);
        // Αποτυχία του preg (backtrack limit σε μεγάλη σελίδα) επιστρέφει null.
        // Κρατάμε το προηγούμενο HTML αντί να σερβίρουμε κενή σελίδα.
        if ($out !== null) {
            $html = $out;
        }
    }

    return $html;
}
