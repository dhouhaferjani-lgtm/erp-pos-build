import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'

export function PrivacyPolicyPage() {
  const { t, i18n } = useTranslation('common')
  const isFrench = i18n.language === 'fr'

  return (
    <div className="min-h-screen bg-gray-50 dark:bg-gray-900">
      <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
        <Link
          to="/"
          className="mb-8 inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('actions.back')}
        </Link>

        <h1 className="mb-8 text-3xl font-bold text-gray-900 dark:text-white">
          {t('legal.privacyPolicy')}
        </h1>

        <div className="prose prose-gray dark:prose-invert max-w-none space-y-8">
          <p className="text-sm text-gray-500 dark:text-gray-400">
            {isFrench ? 'Derniere mise a jour' : 'Last updated'}: 2026-03-20
          </p>

          {/* Section 1 */}
          <section>
            <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
              {isFrench ? '1. Introduction' : '1. Introduction'}
            </h2>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {isFrench
                ? "AutoERP (\"nous\", \"notre\") s'engage a proteger votre vie privee. Cette politique de confidentialite explique comment nous collectons, utilisons et protegeons vos donnees personnelles conformement au Reglement General sur la Protection des Donnees (RGPD) et a la loi Informatique et Libertes."
                : 'AutoERP ("we", "our", "us") is committed to protecting your privacy. This Privacy Policy explains how we collect, use, and protect your personal data in compliance with the General Data Protection Regulation (GDPR) and applicable French and European data protection laws.'}
            </p>
          </section>

          {/* Section 2 */}
          <section>
            <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
              {isFrench ? '2. Donnees collectees' : '2. Data We Collect'}
            </h2>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {isFrench
                ? 'Nous collectons les categories de donnees suivantes :'
                : 'We collect the following categories of data:'}
            </p>
            <ul className="mt-2 list-disc space-y-1 pl-6 text-gray-600 dark:text-gray-300">
              <li>
                <strong>{isFrench ? 'Informations du compte' : 'Account information'}</strong>:{' '}
                {isFrench
                  ? 'nom, adresse e-mail, nom de l\'entreprise, numero de telephone'
                  : 'name, email address, company name, phone number'}
              </li>
              <li>
                <strong>{isFrench ? 'Donnees d\'utilisation' : 'Usage data'}</strong>:{' '}
                {isFrench
                  ? 'journaux d\'acces, actions effectuees, adresses IP, type de navigateur'
                  : 'access logs, actions performed, IP addresses, browser type'}
              </li>
              <li>
                <strong>{isFrench ? 'Donnees commerciales' : 'Business data'}</strong>:{' '}
                {isFrench
                  ? 'factures, devis, inventaire, et autres donnees saisies dans le systeme ERP'
                  : 'invoices, quotes, inventory, and other data you enter into the ERP system'}
              </li>
              <li>
                <strong>Cookies</strong>:{' '}
                {isFrench
                  ? 'cookies essentiels pour l\'authentification et la securite (jeton CSRF, jeton de session)'
                  : 'essential cookies for authentication and security (CSRF token, session token)'}
              </li>
            </ul>
          </section>

          {/* Section 3 */}
          <section>
            <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
              {isFrench ? '3. Finalites du traitement' : '3. Purpose of Processing'}
            </h2>
            <ul className="mt-2 list-disc space-y-1 pl-6 text-gray-600 dark:text-gray-300">
              <li>
                {isFrench
                  ? 'Fourniture et maintenance du service ERP'
                  : 'Provision and maintenance of the ERP service'}
              </li>
              <li>
                {isFrench
                  ? 'Authentification des utilisateurs et securite du compte'
                  : 'User authentication and account security'}
              </li>
              <li>
                {isFrench
                  ? 'Conformite aux obligations legales et fiscales (NF525, facturation electronique)'
                  : 'Compliance with legal and fiscal obligations (NF525, e-invoicing)'}
              </li>
              <li>
                {isFrench
                  ? 'Amelioration du service et support technique'
                  : 'Service improvement and technical support'}
              </li>
            </ul>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {isFrench
                ? 'Base legale : execution du contrat (Article 6(1)(b) RGPD), obligations legales (Article 6(1)(c) RGPD), et interet legitime (Article 6(1)(f) RGPD).'
                : 'Legal basis: contract performance (Article 6(1)(b) GDPR), legal obligations (Article 6(1)(c) GDPR), and legitimate interest (Article 6(1)(f) GDPR).'}
            </p>
          </section>

          {/* Section 4 */}
          <section>
            <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
              {isFrench ? '4. Conservation des donnees' : '4. Data Retention'}
            </h2>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {isFrench
                ? 'Les donnees du compte sont conservees pendant toute la duree de votre abonnement et supprimees dans les 90 jours suivant la cloture du compte. Les donnees fiscales et comptables sont conservees pendant la duree requise par la loi (10 ans en France). Les journaux d\'audit sont conserves pendant 6 ans conformement aux obligations fiscales.'
                : 'Account data is retained for the duration of your subscription and deleted within 90 days of account closure. Fiscal and accounting data is retained for the legally required period (10 years in France). Audit logs are retained for 6 years in compliance with fiscal obligations.'}
            </p>
          </section>

          {/* Section 5 */}
          <section>
            <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
              {isFrench ? '5. Vos droits (RGPD)' : '5. Your Rights (GDPR)'}
            </h2>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {isFrench
                ? 'En vertu du RGPD, vous disposez des droits suivants :'
                : 'Under the GDPR, you have the following rights:'}
            </p>
            <ul className="mt-2 list-disc space-y-1 pl-6 text-gray-600 dark:text-gray-300">
              <li>
                <strong>{isFrench ? 'Droit d\'acces' : 'Right of access'}</strong>:{' '}
                {isFrench
                  ? 'obtenir une copie de vos donnees personnelles'
                  : 'obtain a copy of your personal data'}
              </li>
              <li>
                <strong>{isFrench ? 'Droit de rectification' : 'Right to rectification'}</strong>:{' '}
                {isFrench
                  ? 'corriger des donnees inexactes'
                  : 'correct inaccurate data'}
              </li>
              <li>
                <strong>{isFrench ? 'Droit a l\'effacement' : 'Right to erasure'}</strong>:{' '}
                {isFrench
                  ? 'demander la suppression de vos donnees (sous reserve des obligations legales de conservation)'
                  : 'request deletion of your data (subject to legal retention requirements)'}
              </li>
              <li>
                <strong>{isFrench ? 'Droit a la portabilite' : 'Right to data portability'}</strong>:{' '}
                {isFrench
                  ? 'recevoir vos donnees dans un format structure et lisible par machine'
                  : 'receive your data in a structured, machine-readable format'}
              </li>
              <li>
                <strong>{isFrench ? 'Droit d\'opposition' : 'Right to object'}</strong>:{' '}
                {isFrench
                  ? 'vous opposer au traitement de vos donnees dans certains cas'
                  : 'object to processing of your data in certain circumstances'}
              </li>
            </ul>
          </section>

          {/* Section 6 */}
          <section>
            <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
              {isFrench ? '6. Securite des donnees' : '6. Data Security'}
            </h2>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {isFrench
                ? 'Nous mettons en oeuvre des mesures techniques et organisationnelles appropriees pour proteger vos donnees, notamment le chiffrement en transit (TLS) et au repos, le controle d\'acces base sur les roles, et les chaines de hachage cryptographiques pour l\'integrite des donnees fiscales.'
                : 'We implement appropriate technical and organizational measures to protect your data, including encryption in transit (TLS) and at rest, role-based access control, and cryptographic hash chains for fiscal data integrity.'}
            </p>
          </section>

          {/* Section 7 */}
          <section>
            <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
              {isFrench ? '7. Contact' : '7. Contact'}
            </h2>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {isFrench
                ? 'Pour toute question relative a cette politique ou pour exercer vos droits, contactez-nous a :'
                : 'For any questions regarding this policy or to exercise your rights, contact us at:'}
            </p>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              <a
                href="mailto:privacy@company.com"
                className="text-primary-600 hover:text-primary-500 dark:text-primary-400"
              >
                privacy@company.com
              </a>
            </p>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {isFrench
                ? 'Vous avez egalement le droit de deposer une reclamation aupres de la CNIL (Commission Nationale de l\'Informatique et des Libertes).'
                : 'You also have the right to lodge a complaint with the CNIL (Commission Nationale de l\'Informatique et des Libertes) or your local data protection authority.'}
            </p>
          </section>
        </div>
      </div>
    </div>
  )
}
