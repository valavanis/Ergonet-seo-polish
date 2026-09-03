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

    // ── 2β. οι μικρογραφίες της γκαλερί δεν έχουν όνομα ─────────────────────
    //
    // Σε προϊόν με ΠΟΛΛΕΣ εικόνες ο πυρήνας εκπέμπει anchors που περιέχουν μόνο
    // <img> χωρίς alt: `cm-thumbnails-mini` (μικρογραφίες) και
    // `cm-image-previewer` (μεγέθυνση). Χωρίς προσβάσιμο όνομα ο αναγνώστης
    // οθόνης ανακοινώνει «σύνδεσμος» και τίποτε άλλο. Δεν φαίνεται σε προϊόντα
    // με μία εικόνα — γι᾽ αυτό έλειπε από τους ελέγχους μας μέχρι 02/09/2026.
    //
    // Η ετικέτα είναι το ΟΝΟΜΑ ΤΟΥ ΠΡΟΪΟΝΤΟΣ από τον h1 της ίδιας σελίδας,
    // συν αύξοντα αριθμό. Καμία νέα γλωσσική μεταβλητή: ο αριθμός σε παρένθεση
    // διαβάζεται το ίδιο σε κάθε γλώσσα, ενώ μια σταθερή ελληνική λέξη θα
    // ακουγόταν λάθος στο αγγλικό storefront.
    if (preg_match('~<h1[^>]*>(.*?)</h1>~is', $html, $h1)) {
        $name = trim(html_entity_decode(strip_tags($h1[1]), ENT_QUOTES, 'UTF-8'));
        if ($name !== '') {
            $name = preg_replace('~\s+~u', ' ', $name);
            $n = 0;
            $out = preg_replace_callback(
                '~<a(?![^>]*aria-label)((?=[^>]*class="[^"]*(?:cm-thumbnails-mini|cm-image-previewer)[^"]*")[^>]*)>~i',
                static function (array $m) use ($name, &$n): string {
                    $n++;
                    // Ο μετρητής μετρά ΚΑΙ τις δύο οικογένειες anchor, οπότε η
                    // αρίθμηση είναι μοναδική μέσα στη σελίδα — αυτό αρκεί, δεν
                    // χρειάζεται να αντιστοιχεί σε θέση εικόνας.
                    return '<a aria-label="'
                        . htmlspecialchars($name . ' (' . $n . ')', ENT_QUOTES, 'UTF-8')
                        . '"' . $m[1] . '>';
                },
                $html
            );
            if ($out !== null) {
                $html = $out;
            }
        }
    }

    // ── 2γ. το άλμα h2 → h4 στον τίτλο αξιολόγησης ──────────────────────────
    //
    // Ο πυρήνας γράφει <h4 class="ty-product-review-write-review__title"> ενώ η
    // προηγούμενη ενότητα της σελίδας είναι h2. Άλμα ενός επιπέδου, δηλαδή
    // παραβίαση heading-order: όποιος πλοηγείται με επικεφαλίδες νομίζει ότι
    // έχασε μια ενότητα.
    //
    // Γίνεται εδώ και όχι με override template: το αρχείο είναι του πυρήνα και
    // ένα αντίγραφο στο παιδικό θέμα θα πάγωνε, χάνοντας κάθε μελλοντική
    // διόρθωση του CS-Cart — ο ίδιος λόγος που υπάρχει όλο αυτό το φίλτρο.
    $out = preg_replace(
        '~<h4([^>]*ty-product-review-write-review__title[^>]*)>(.*?)</h4>~is',
        '<h3$1>$2</h3>',
        $html
    );
    if ($out !== null) {
        $html = $out;
    }

    // ── 2δ. title σε <iframe> που δεν έχει ──────────────────────────────────
    //
    // Το axe/Lighthouse αναφέρει «<frame> or <iframe> elements do not have a
    // title»: οι χρήστες αναγνώστη οθόνης ακούν σκέτο «iframe», χωρίς καμία
    // ένδειξη περιεχομένου.
    //
    // Μετρημένο 03/09/2026 σε σελίδα προϊόντος: τα ένθετα βίντεο YouTube που
    // μπαίνουν από τον WYSIWYG μέσα στην περιγραφή προϊόντος βγαίνουν ΧΩΡΙΣ
    // title (~231 προϊόντα έχουν βίντεο).
    //
    // Γίνεται εδώ και ΟΧΙ με μαζική επεξεργασία των περιγραφών στη βάση: το
    // περιεχόμενο ανήκει στον πελάτη, μια μαζική εγγραφή θέλει εφεδρικό και
    // δοκιμασμένη επαναφορά, και θα ξανασπάσει με την πρώτη επεξεργασία από το
    // admin. Το φίλτρο απόδοσης το διορθώνει για πάντα και αναιρείται σβήνοντας
    // ένα μπλοκ.
    //
    // Ο τίτλος βγαίνει από τον πάροχο, όχι γενικό «πλαίσιο»: ο αναγνώστης
    // οθόνης πρέπει να μαθαίνει ΤΙ περιέχει, αλλιώς η ετικέτα περνά τον έλεγχο
    // χωρίς να βοηθά κανέναν. Άγνωστη πηγή μένει ΑΠΕΙΡΑΧΤΗ.
    $out = preg_replace_callback(
        '~<iframe(?![^>]*\stitle\s*=)([^>]*)>~i',
        static function (array $m): string {
            // ΠΡΟΣΟΧΗ στο data-cc-src: ο αποκλειστής cookies μετονομάζει το
            // src ώστε το πλαίσιο να ΜΗΝ φορτώσει πριν τη συγκατάθεση. Ένα
            // φίλτρο που κοιτά μόνο `src` προσπερνά σιωπηλά ΚΑΘΕ ένθετο βίντεο
            // — δηλαδή ακριβώς τα iframe που έχουν το πρόβλημα. Μετρημένο
            // 03/09/2026: η πρώτη εκδοχή αυτού του μπλοκ ήταν μη-λειτουργία.
            if (preg_match('~\s(?:data-cc-)?src\s*=\s*["\']([^"\']+)~i', $m[1], $s) !== 1) {
                // Χωρίς καμία πηγή δεν ξέρουμε τι είναι — και τα iframe χωρίς
                // src είναι σχεδόν πάντα κρυφά pixel, που το axe ούτως ή άλλως
                // αγνοεί. Καμία ετικέτα είναι καλύτερη από ψεύτικη.
                return $m[0];
            }
            $url = $s[1];
            $map = [
                'youtube.com'          => 'Βίντεο YouTube',
                'youtube-nocookie.com' => 'Βίντεο YouTube',
                'youtu.be'             => 'Βίντεο YouTube',
                'player.vimeo.com'     => 'Βίντεο Vimeo',
                'google.com/maps'      => 'Χάρτης Google',
                'maps.google.'         => 'Χάρτης Google',
            ];
            foreach ($map as $needle => $label) {
                if (stripos($url, $needle) !== false) {
                    return '<iframe title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"' . $m[1] . '>';
                }
            }
            return $m[0];
        },
        $html
    );
    if ($out !== null) {
        $html = $out;
    }

    // ── 3. δέσμευση ύψους για τα hero banner (CLS) ───────────────────────────
    //
    // ΠΡΕΠΕΙ να μπει στο <head>: αν φτάσει μετά την πρώτη απόδοση, η μετατόπιση
    // έχει ήδη συμβεί και μετρηθεί.
    $head = stripos($html, '</head>');
    if ($head !== false) {
        $html = substr_replace($html, fn_ergonet_seo_polish_cls_reserve(), $head, 0);
    }

    // ── 4. το δέντρο προσβασιμότητας του accordion ───────────────────────────
    $pos = strripos($html, '</body>');
    if ($pos !== false) {
        $html = substr_replace($html, fn_ergonet_seo_polish_accordion_script(), $pos, 0);
    }

    // ── 5. ορόσημο «main» ────────────────────────────────────────────────────
    //
    // Το θέμα δεν εκπέμπει ούτε <main> ούτε role="main" σε καμία σελίδα
    // (μετρημένο 02/09/2026: 0 εμφανίσεις). Χωρίς αυτό, όποιος πλοηγείται με
    // αναγνώστη οθόνης δεν μπορεί να πηδήξει στο περιεχόμενο και ακούει από την
    // αρχή ολόκληρη την κεφαλίδα, το μενού και τη γραμμή εμπιστοσύνης.
    //
    // Ο ρόλος μπαίνει στο ΥΠΑΡΧΟΝ δοχείο αντί να τυλιχτεί σε νέο <main>: το
    // `.tygh-content` κουβαλά ήδη layout, και ένα επιπλέον στοιχείο ανάμεσα σε
    // γονέα και παιδί σπάει τους flex/grid κανόνες του θέματος.
    //
    // Μία μόνο αντικατάσταση: το landmark πρέπει να είναι μοναδικό, αλλιώς ο
    // αναγνώστης βρίσκει δύο «κύρια» περιεχόμενα και το ορόσημο χάνει νόημα.
    if (stripos($html, 'role="main"') === false && stripos($html, '<main') === false) {
        $needle = '<div class="tygh-content clearfix"';
        $at = stripos($html, $needle);
        if ($at !== false) {
            $html = substr_replace($html, $needle . ' role="main"', $at, strlen($needle));
        }
    }

    return $html;
}

/**
 * Δεσμεύει το τελικό ύψος των hero banner πριν τρέξει το owl carousel.
 *
 * ΤΙ ΣΥΜΒΑΙΝΕΙ: το `.banners.owl-carousel` περιέχει τέσσερα slides. Πριν
 * αρχικοποιηθεί το owl, κάθονται ΔΙΠΛΑ-ΔΙΠΛΑ στο 25% πλάτος το καθένα — άρα το
 * δοχείο βγαίνει στο ένα τέταρτο του τελικού ύψους. Μόλις τρέξει το owl, ένα
 * slide πιάνει όλο το πλάτος και το δοχείο τετραπλασιάζεται, σπρώχνοντας όλη
 * τη σελίδα προς τα κάτω.
 *
 * Μετρημένο 02/09/2026 στην αρχική (CPU 4× + Slow 4G):
 *
 *   desktop 1440   δοχείο  94 → 376 px   CLS 0,091
 *   mobile   412   δοχείο 101 → 449 px   CLS 0,186
 *
 * ΓΙΑΤΙ min-height ΚΑΙ ΟΧΙ ΑΠΟΚΡΥΨΗ ΤΩΝ SLIDES: το να κρύψω τα 2-4 μέχρι να
 * τρέξει το owl θα έδινε επίσης σταθερό ύψος, αλλά με JavaScript κλειστή ο
 * επισκέπτης θα έβλεπε ΕΝΑ banner αντί για τέσσερα. Η προεπιλογή πρέπει να
 * είναι η ορατή κατάσταση. Με min-height δεν κρύβεται τίποτα: το δοχείο απλώς
 * έχει από την αρχή το ύψος που θα είχε στο τέλος, και ό,τι γίνεται μέσα του
 * δεν μετακινεί τη σελίδα.
 *
 * ΔΥΟ ΜΠΛΟΚ, ΟΧΙ ΕΝΑ: το θέμα εκπέμπει χωριστό hero ανά πλάτος —
 * `.homepage-banners` (hidden-phone) με εικόνα 1920×500, και ένα δεύτερο
 * (hidden-desktop) με εικόνα 767×767. Διαφορετική αναλογία, διαφορετικός
 * υπολογισμός.
 *
 * Τα +37px στο mobile είναι οι κουκκίδες πλοήγησης κάτω από την εικόνα
 * (μετρημένο: δοχείο 449 px, εικόνα 412 px).
 *
 * Το 100vw περιλαμβάνει τη μπάρα κύλισης, οπότε σε desktop με scrollbar το
 * δεσμευμένο ύψος βγαίνει ελάχιστα μεγαλύτερο από το πραγματικό. Υπερ-δέσμευση
 * δίνει ένα κενό μερικών pixel· υπο-δέσμευση θα ξαναέφερνε τη μετατόπιση.
 *
 * @return string
 */
function fn_ergonet_seo_polish_cls_reserve(): string
{
    return '<style id="erg-cls-reserve">'
        . '@media (min-width:768px){.homepage-banners .banners.owl-carousel{min-height:26.05vw}}'
        . '@media (max-width:767px){.hidden-desktop .banners.owl-carousel{min-height:calc(100vw + 37px)}}'
        // ── αντίθεση WCAG AA ────────────────────────────────────────────────
        //
        // Τρία χρώματα που ΔΕΝ υπάρχουν κυριολεκτικά σε κανένα δικό μας αρχείο:
        // τα δύο πρώτα τα παράγει το LESS με συναρτήσεις, το τρίτο ζει στο ΓΟΝΙΚΟ
        // θέμα (responsive/css/styles.less). Επέμβαση στο γονικό θα χανόταν στην
        // επόμενη αναβάθμιση του CS-Cart, οπότε μπαίνουν εδώ ως override.
        //
        // Μετρημένα σε λευκό, 02/09/2026:
        //   .ty-breadcrumbs__a        #A5AFB9  2,23 → #767676  4,54
        //   .ty-value-changer__*      #C2C9D0  1,67 → #697888  4,52
        //   .ui-accordion-header      λευκό σε #BDC3C7  1,78
        //
        // Στην κεφαλίδα του accordion σκουραίνει το ΚΕΙΜΕΝΟ (7,10) και όχι το
        // φόντο: το ανοιχτό γκρι είναι μέρος της εμφάνισης, το λευκό κείμενο
        // πάνω του ήταν απλώς αδιάβαστο.
        . '.ty-breadcrumbs .ty-breadcrumbs__a,.ty-breadcrumbs .ty-breadcrumbs__slash{color:#767676}'
        . '.ty-value-changer .ty-value-changer__decrease,'
        . '.ty-value-changer .ty-value-changer__increase{color:#697888}'
        // Ο κανόνας του θέματος είναι
        //   .ty-accordion .ui-accordion-header.ui-state-active { background:#bdc3c7; color:white }
        // δηλαδή ειδικότητα 0-3-0. Ένας override 0-2-0 χάνει σιωπηλά — πρέπει
        // να επαναληφθεί ΟΛΟΚΛΗΡΟΣ ο επιλογέας, και επειδή αυτό το <style>
        // μπαίνει τελευταίο στο <head> κερδίζει στην ισοπαλία.
        . '.ty-accordion .ui-accordion-header.ui-state-active,'
        . '.ty-accordion .ui-accordion-header.ui-state-active a{color:#333}'
        // Και η ΚΛΕΙΣΤΗ κεφαλίδα: #7c7e80 πάνω στο #e5ebec δίνει 3,38:1.
        // Ίδια απόχρωση στο 84% της φωτεινότητας → #686a6c, 4,51:1.
        . '.ty-accordion .ui-accordion-header{color:#686a6c}'
        // Το blog είναι core addon του CS-Cart, όχι δικό μας: #adadad σε λευκό
        // δίνει 2,24:1. Πέντε ημερομηνίες στην αρχική.
        . '.ty-blog .ty-blog__date,.ty-blog-grid .ty-blog__date,'
        . '.ty-blog-recent-posts-scroller__item .ty-blog__date{color:#767676}'
        // ── στόχοι αφής ≥24×24 ──────────────────────────────────────────────
        //
        // Οι μετρητές ποσότητας του πυρήνα αποδίδονται 16×16 σε mobile 412px.
        // Δεν μεγαλώνει το ΕΙΚΟΝΙΔΙΟ, μόνο η επιφάνεια που δέχεται το δάχτυλο:
        // το γλυφικό μένει κεντραρισμένο μέσα σε 24×24 με flex.
        . '.ty-value-changer .ty-value-changer__decrease,'
        . '.ty-value-changer .ty-value-changer__increase{'
        . 'min-width:24px;min-height:24px;display:inline-flex;'
        . 'align-items:center;justify-content:center}'
        //
        // Αυτόνομοι σύνδεσμοι ενέργειας που αποδίδονται 22px ψηλοί (line-height
        // 22 σε κείμενο 13-14px). ΔΕΝ μπαίνει καθολικός κανόνας σε κάθε <a>:
        // ένας σύνδεσμος μέσα σε παράγραφο πρέπει να μείνει inline, αλλιώς
        // σπάει η ροή του κειμένου — και το WCAG 2.5.8 τον εξαιρεί ούτως ή
        // άλλως. Απαριθμούνται μόνο όσοι στέκονται μόνοι τους.
        . 'a.cm-dialog-opener,.contact-desc h2 a,'
        . '.ty-dropdown-box__title.cm-combination>a,.brand .ty-features-list a{'
        . 'display:inline-flex;align-items:center;justify-content:center;'
        // Και ΠΛΑΤΟΣ: ο σύνδεσμος μάρκας «Elo» βγήκε 16px φαρδύς. Το κριτήριο
        // είναι 24×24, όχι μόνο ύψος — ένα κοντό όνομα μάρκας κόβεται στο πλάτος.
        . 'min-height:24px;min-width:24px}'
        //
        // Άγκυρες που τυλίγουν ΜΟΝΟ εικόνα (λογότυπο, μικρογραφίες scroller):
        // ως inline στοιχεία παίρνουν το ύψος της γραμμής, όχι της εικόνας, και
        // μετριούνται 22px ενώ η εικόνα από κάτω είναι πολύ μεγαλύτερη. Το
        // inline-block κάνει την άγκυρα να αγκαλιάσει την εικόνα — ο στόχος
        // γίνεται όσο και το ορατό αντικείμενο, χωρίς καμία οπτική αλλαγή.
        . '.ty-logo-container>a,.ty-scroller-list__img-block>a{display:inline-block}'
        //
        // Δικό μας banner συγκατάθεσης: το «Προσαρμογή επιλογών» μετρήθηκε
        // 356×19. Ειδικότητα 0-2-0 για να νικήσει το banner.css που φορτώνεται
        // μετά το <head>.
        . '.ergonet-cc-banner .ergonet-cc-banner__btn,'
        . '.ergonet-cc-banner .ergonet-cc-banner__link{'
        . 'display:inline-flex;align-items:center;justify-content:center;min-height:24px}'
        //
        // Το newsletter γράφει την υπόδειξη ΜΕΣΑ στο value (μοτίβο cm-hint του
        // πυρήνα, όχι placeholder). Μετρήθηκε #c9c9c9 σε λευκό = 1,65:1 — το
        // χειρότερο νούμερο ολόκληρης της σελίδας. Είναι ορατό κείμενο, άρα
        // ισχύει το 4,5:1 κανονικά.
        . 'input.ty-input-text.cm-hint,textarea.cm-hint{color:#767676}'
        . '</style>';
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
// Ονόματα για τα <select> που φτάνουν με AJAX.
//
// Ο επιλογέας παραλλαγών ΔΕΝ υπάρχει στο HTML της σελίδας: τον φέρνει
// ξεχωριστό AJAX αίτημα μετά τη φόρτωση. Το output filter του addon δεν τον
// βλέπει ποτέ (βγαίνει νωρίς σε AJAX_REQUEST), οπότε η ΜΟΝΗ διαδρομή που τον
// φτάνει είναι ο MutationObserver που ήδη τρέχει εδώ.
//
// Η ετικέτα ΔΙΑΒΑΖΕΤΑΙ από τη σελίδα, δεν εφευρίσκεται: αν δεν βρεθεί ορατό
// κείμενο, το select μένει ως έχει. Λάθος όνομα είναι χειρότερο από κανένα —
// ο αναγνώστης οθόνης θα διάβαζε κάτι που δεν αντιστοιχεί στο χειριστήριο.
function labelSelects(){
 var sels=document.querySelectorAll("select:not([aria-label]):not([aria-labelledby]):not([title])"),i;
 for(i=0;i<sels.length;i++){
  var el=sels[i];
  if(el.id&&document.querySelector('label[for="'+el.id+'"]')){continue;}
  if(el.closest&&el.closest("label")){continue;}
  var txt="",grp=el.closest?el.closest(".ty-control-group,.ty-product-options__item,.ty-product-block__field-group"):null;
  if(grp){var t=grp.querySelector(".ty-control-group__title,.ty-product-options__title,label");if(t){txt=t.textContent;}}
  if(!txt){var prev=el.previousElementSibling;if(prev&&prev.textContent){txt=prev.textContent;}}
  txt=(txt||"").replace(/\s+/g," ").replace(/[:*\s]+$/,"").trim();
  if(txt&&txt.length<=64){el.setAttribute("aria-label",txt);}
 }
}
function normalise(){
 var accs=document.querySelectorAll(SEL),i,j,n;
 labelSelects();
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
  {subtree:true,childList:true,attributes:true,attributeFilter:["role","aria-selected","aria-label"]});
}
if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",boot);}else{boot();}
})();</script>
JS;
    return $js;
}
