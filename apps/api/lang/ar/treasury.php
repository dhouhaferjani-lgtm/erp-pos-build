<?php

declare(strict_types=1);

return [
    /*
     * Labels of the two repositories a freshly registered tenant is born with
     * (DPA lane H-3). Read by PaymentRepositorySeeder under the registering
     * company's locale — a tenant must never be handed English defaults it did
     * not ask for. The operator renames them freely afterwards; these are
     * creation-time labels, not a runtime lookup.
     *
     * Terminology matches the web app's Arabic treasury bundle
     * (apps/web/src/locales/ar/treasury.json): صندوق نقدي = cash register,
     * خزنة = safe.
     */
    'default_repositories' => [
        'cash_register' => 'الصندوق النقدي الرئيسي',
        'safe' => 'خزنة المكتب',
    ],
    /*
     * C-0a0 — سبب رفض تخصيص الدفعة
     * (SPEC-document-lifecycle-dimensions §2.1, `AllocationRefusalReason`).
     */
    'allocation_refused' => [
        'document_not_live' => 'لا يمكن لهذا المستند استلام دفعة في حالته الحالية. يمكن دفع المستندات المؤكدة أو المرحّلة فقط.',
        'historical_opening_provenance' => 'هذا الرصيد الافتتاحي إشعار دائن، أو تعذّر تحديد جهته (عميل أم مورّد) من الاستيراد الذي أنشأه. لا يمكن تسويته بعد: صحّحه عبر تصحيح رصيد افتتاحي، أو تواصل مع الدعم.',
        'pos_derived_provenance' => 'هذه الفاتورة ناتجة عن بيع آجل في نقطة البيع. تتم تسويتها عبر حساب العميل وليس من هذه الشاشة.',
        'status_not_allocatable_for_type' => 'لا يمكن لهذا المستند استلام دفعة في حالته الحالية. قم بترحيله أولاً ثم سجّل الدفعة.',
        'outward_document_type' => 'إشعار الدائن مبلغ مستحق للطرف الآخر: خصّصه على مستند آخر أو قم برده، بدلاً من تحصيل دفعة عليه.',
        'purchase_order_wrong_direction' => 'لا يمكن لأمر الشراء استلام دفعة. سجّل الدفعة على فاتورة المورّد بعد ترحيلها.',
        'type_never_allocatable' => 'هذا النوع من المستندات لا يحمل أي رصيد يمكن لدفعة أن تسوّيه.',
        'payable_not_settleable_here' => 'هذه فاتورة مورّد. سجّل الدفعة عبر مسار دفع المورّدين الذي يدفع للمورّد ويسوّي الذمة الدائنة.',
        'partner_role_mismatch' => 'نوع هذه الوثيقة لا يطابق دور الشريك، لذا لا يمكن تحديد اتجاه الدفع. يجب أن تعود فاتورة العميل إلى عميل وفاتورة المورّد إلى مورّد. إذا كان هذا الشريك الاثنين معًا، فاضبط نوعه على «كلاهما»؛ وإلا فصحّح الوثيقة.',
    ],
];
