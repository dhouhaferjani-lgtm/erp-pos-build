<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| N-6 — printout marker for a fiscal document that is not sealed here
|--------------------------------------------------------------------------
| ONLY the `posting_marker` block lives here, deliberately. Laravel's
| translator falls back to `fallback_locale` PER KEY, so every other
| `documents.*` key keeps resolving through `lang/en/documents.php` exactly as
| it did before this file existed — creating it cannot regress anything.
|
| CAVEAT, and it is why r1 shipped en/fr only: the PDF stack's font does not
| SHAPE Arabic, so these strings are correct but may render unjoined in a
| generated PDF. That is the Arabic-PDF font lane's problem, not a reason to
| leave an Arabic-locale tenant reading an English fiscal statement. The web
| print surface (`CreditNoteDetail.tsx`) renders them properly today.
*/

return [
    /*
    | Campaign W2-6 (gate r2 C2). Added alongside `posting_marker` because this is an
    | API refusal rendered in the WEB UI, not in the PDF stack — the Arabic-shaping
    | caveat above applies to generated PDFs only, so there is no reason to leave an
    | Arabic-locale tenant reading an English refusal here.
    */
    'purchase_order' => [
        'line_unpriced' => 'السطر :line (« :description ») بدون سعر وحدة. أدخل سعر المورّد في كل سطر قبل تأكيد أمر الشراء: التأكيد بقيمة 0.000 سيُدخل البضاعة إلى المخزون بقيمة صفرية ويُفسد تقييم مخزونك.',
    ],

    /*
    | C-F0 / SPEC §2.4 (F-13, F-64, F-95) — العرض المبدئي (proforma).
    | كما في `posting_marker` أدناه، تحفّظ تشكيل الخط في ملفات PDF قائم ولا يبرر
    | ترك مستخدم عربي أمام نص إنجليزي.
    */
    'proforma' => [
        'title' => 'مبدئية — مستند غير ضريبي',
        'detail' => 'هذا تقدير صادر قبل إدخال البيع في الحسابات. ليس مستنداً ضريبياً نهائياً، ولا يحمل أي ختم، ولا ينشئ أي حق في الخصم. سيُصدر مستند نهائي بعد إدخال البيع.',
        'estimated_total' => 'المجموع التقديري',
    ],

    'posting_marker' => [
        'cancelled_title' => 'ملغاة — تم إبطال هذا المستند',
        'cancelled_detail' => 'تم ترحيل هذا المستند وختمه، ثم أُلغي. يبقى ختمه الضريبي في سلسلة التجزئة؛ أما المستند نفسه فباطل ولا يجوز استخدامه كمستند إثبات.',
        'cancelled_unsealed_detail' => 'تم إلغاء هذا المستند ولا يجوز استخدامه كمستند إثبات. لم يُرحَّل إلى الحسابات قط ولا يحمل أي ختم ضريبي.',
        'historical_title' => 'رصيد افتتاحي — منقول من نظام سابق',
        'historical_detail' => 'يسجل هذا المستند رصيداً كان قائماً بالفعل عند فتح الحسابات هنا. تم ترحيله في النظام السابق ولا يحمل ختماً ضريبياً في هذا النظام.',
    ],
];
