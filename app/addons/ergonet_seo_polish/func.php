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

    // ── 3. το δέντρο προσβασιμότητας του accordion ───────────────────────────
    $pos = strripos($html, '</body>');
    if ($pos !== false) {
        $html = substr_replace($html, fn_ergonet_seo_polish_accordion_script(), $pos, 0);
    }

    return $html;
}

/**
 * Το ΜΟΝΟ κομμάτι αυτού του addon που είναι JavaScript, και ο λόγος είναι ότι
 * το ελάττωμα το γεννά JavaScript.
 *
 * ΤΙ ΣΥΜΒΑΙΝΕΙ: ο server στέλνει καρτέλες — `<ul class="ty-tabs__list">`. Σε
 * πλάτος κινητού το responsive.js του CS-Cart τις μετατρέπει σε accordion με το
 * jQuery UI 1.13, το οποίο χτίζει εξαρχής όλη τη δομή και της δίνει το ΠΑΛΙΟ
 * μοτίβο tabs: `role="tablist"` στον περιέκτη, `role="tab"` στα <h3>,
 * `role="tabpanel"` στα panels.
 *
 * ΓΙΑΤΙ ΕΙΝΑΙ ΛΑΘΟΣ: κατά το ARIA, ένα `tablist` επιτρέπεται να περιέχει μόνο
 * `tab`. Εδώ τα panels κάθονται ΜΕΣΑ στο tablist — έπρεπε να είναι αδέλφια του.
 * Το axe-core (που τρέχει το Lighthouse) το αναφέρει ως aria-required-children.
 *
 * ΓΙΑΤΙ ΟΧΙ ΦΙΛΤΡΟ HTML: τίποτε από αυτά δεν υπάρχει στο HTML που φεύγει από τον
 * server. Ούτε ο περιέκτης, ούτε τα <h3>, ούτε οι ρόλοι. Ένα φίλτρο κειμένου δεν
 * έχει τι να πιάσει.
 *
 * ΓΙΑΤΙ ΑΦΑΙΡΕΙΤΑΙ Ο ΡΟΛΟΣ ΚΑΙ ΔΕΝ ΓΙΝΕΤΑΙ button: το `role="button"` ΔΕΝ
 * επιτρέπεται σε <h3> (δοκιμάστηκε — το axe το κόβει ως aria-allowed-role).
 * Αφαιρώντας τον ρόλο, τα <h3> ξαναγίνονται πραγματικές επικεφαλίδες, που είναι
 * ΚΑΛΥΤΕΡΟ από πριν: το `role="tab"` ήδη ακύρωνε τη σημασιολογία επικεφαλίδας,
 * άρα δεν χάνεται τίποτα που δεν είχε ήδη χαθεί, και κερδίζεται η πλοήγηση με
 * επικεφαλίδες. Το `aria-expanded`/`aria-controls` μένουν και κρατούν την
 * κατάσταση· το `aria-selected` φεύγει γιατί χωρίς `role="tab"` είναι άκυρο.
 *
 * ΓΙΑΤΙ MutationObserver: το jQuery UI ξαναγράφει αυτά τα attributes σε ΚΑΘΕ
 * άνοιγμα panel, και το responsive.js κάνει destroy/create σε κάθε αλλαγή
 * πλάτους. Μια εφάπαξ διόρθωση θα κρατούσε μέχρι το πρώτο κλικ.
 *
 * Η normalise() είναι ιδεμποτής — οι επιλογείς της ταιριάζουν μόνο σε ό,τι ΔΕΝ
 * έχει ήδη διορθωθεί, οπότε το δεύτερο πέρασμα δεν γράφει τίποτα και ο βρόχος
 * observer → εγγραφή → observer τερματίζει από μόνος του.
 *
 * @return string
 */
function fn_ergonet_seo_polish_accordion_script(): string
{
    static $js = <<<'JS'
<script>(function(){"use strict";
var SEL=".cm-accordion,.ui-accordion",pending=false;
function normalise(){
 var accs=document.querySelectorAll(SEL),i,j,n;
 for(i=0;i<accs.length;i++){
  var a=accs[i];
  if(a.getAttribute("role")==="tablist"){a.removeAttribute("role");}
  n=a.querySelectorAll('[role="tab"]');
  for(j=0;j<n.length;j++){n[j].removeAttribute("role");}
  n=a.querySelectorAll("[aria-selected]");
  for(j=0;j<n.length;j++){n[j].removeAttribute("aria-selected");}
  n=a.querySelectorAll('[role="tabpanel"]');
  for(j=0;j<n.length;j++){n[j].setAttribute("role","region");}
 }
}
function schedule(){
 if(pending){return;}
 pending=true;
 (window.requestAnimationFrame||window.setTimeout)(function(){pending=false;normalise();},0);
}
function boot(){
 normalise();
 if(typeof MutationObserver!=="function"||!document.body){return;}
 new MutationObserver(schedule).observe(document.body,
  {subtree:true,childList:true,attributes:true,attributeFilter:["role","aria-selected"]});
}
if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",boot);}else{boot();}
})();</script>
JS;
    return $js;
}
