import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

export function TermsOfServicePage() {
  const { t, i18n } = useTranslation('common')
  const isFrench = i18n.language === 'fr'

  return (
    <div className={`min-h-screen ${colorTokens.surface.page} ${colorTokens.variants.darkBgGray900}`}>
      <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
        <Link
          to="/"
          className={`mb-8 inline-flex items-center gap-2 text-sm ${colorTokens.text.subtle} ${colorTokens.intent.neutral.textHoverStrong} ${colorTokens.text.darkDisabled} ${colorTokens.intent.neutral.darkTextHoverStrong}`}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('actions.back')}
        </Link>

        <h1 className={`mb-8 text-3xl font-bold ${colorTokens.text.primary} ${colorTokens.text.darkInverse}`}>
          {t('legal.termsOfService')}
        </h1>

        <div className="prose prose-gray dark:prose-invert max-w-none space-y-8">
          <p className={`text-sm ${colorTokens.text.subtle} ${colorTokens.text.darkDisabled}`}>
            {isFrench ? 'Derniere mise a jour' : 'Last updated'}: 2026-03-20
          </p>

          {/* Section 1 */}
          <section>
            <h2 className={`text-xl font-semibold ${colorTokens.text.primary} ${colorTokens.text.darkInverse}`}>
              {isFrench ? '1. Acceptation des conditions' : '1. Acceptance of Terms'}
            </h2>
            <p className={`mt-2 ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray300}`}>
              {isFrench
                ? 'En accedant et en utilisant AutoERP (le "Service"), vous acceptez d\'etre lie par les presentes Conditions d\'Utilisation. Si vous n\'acceptez pas ces conditions, vous ne devez pas utiliser le Service. Ces conditions constituent un accord juridique entre vous (ou l\'entite que vous representez) et AutoERP.'
                : 'By accessing and using AutoERP (the "Service"), you agree to be bound by these Terms of Service. If you do not agree to these terms, you must not use the Service. These terms constitute a legal agreement between you (or the entity you represent) and AutoERP.'}
            </p>
          </section>

          {/* Section 2 */}
          <section>
            <h2 className={`text-xl font-semibold ${colorTokens.text.primary} ${colorTokens.text.darkInverse}`}>
              {isFrench ? '2. Description du service' : '2. Service Description'}
            </h2>
            <p className={`mt-2 ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray300}`}>
              {isFrench
                ? 'AutoERP est une solution logicielle de planification des ressources d\'entreprise (ERP) en mode SaaS, comprenant la gestion des ventes, des achats, de l\'inventaire, de la tresorerie, de la comptabilite et de la conformite fiscale. Le Service est fourni sur la base d\'un abonnement et est destine a un usage professionnel (B2B).'
                : 'AutoERP is a Software-as-a-Service (SaaS) enterprise resource planning (ERP) solution, including sales, purchasing, inventory, treasury, accounting, and fiscal compliance management. The Service is provided on a subscription basis and is intended for professional (B2B) use.'}
            </p>
          </section>

          {/* Section 3 */}
          <section>
            <h2 className={`text-xl font-semibold ${colorTokens.text.primary} ${colorTokens.text.darkInverse}`}>
              {isFrench ? '3. Comptes utilisateur' : '3. User Accounts'}
            </h2>
            <p className={`mt-2 ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray300}`}>
              {isFrench
                ? 'Vous etes responsable du maintien de la confidentialite de vos identifiants de connexion et de toutes les activites effectuees sous votre compte. Vous devez nous informer immediatement de toute utilisation non autorisee de votre compte.'
                : 'You are responsible for maintaining the confidentiality of your login credentials and for all activities that occur under your account. You must notify us immediately of any unauthorized use of your account.'}
            </p>
          </section>

          {/* Section 4 */}
          <section>
            <h2 className={`text-xl font-semibold ${colorTokens.text.primary} ${colorTokens.text.darkInverse}`}>
              {isFrench ? '4. Responsabilites de l\'utilisateur' : '4. User Responsibilities'}
            </h2>
            <ul className={`mt-2 list-disc space-y-1 pl-6 ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray300}`}>
              <li>
                {isFrench
                  ? 'Fournir des informations exactes et a jour lors de l\'inscription et de l\'utilisation du Service'
                  : 'Provide accurate and up-to-date information during registration and use of the Service'}
              </li>
              <li>
                {isFrench
                  ? 'Se conformer a toutes les lois et reglementations applicables, y compris les obligations fiscales'
                  : 'Comply with all applicable laws and regulations, including fiscal obligations'}
              </li>
              <li>
                {isFrench
                  ? 'Ne pas tenter d\'acceder a des systemes ou donnees non autorises'
                  : 'Not attempt to access unauthorized systems or data'}
              </li>
              <li>
                {isFrench
                  ? 'Ne pas utiliser le Service a des fins illegales ou frauduleuses'
                  : 'Not use the Service for illegal or fraudulent purposes'}
              </li>
              <li>
                {isFrench
                  ? 'Maintenir des sauvegardes adequates de vos donnees commerciales'
                  : 'Maintain adequate backups of your business data'}
              </li>
            </ul>
          </section>

          {/* Section 5 */}
          <section>
            <h2 className={`text-xl font-semibold ${colorTokens.text.primary} ${colorTokens.text.darkInverse}`}>
              {isFrench ? '5. Propriete intellectuelle' : '5. Intellectual Property'}
            </h2>
            <p className={`mt-2 ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray300}`}>
              {isFrench
                ? 'Le Service, y compris son code source, son design, ses fonctionnalites et sa documentation, est la propriete exclusive d\'AutoERP. Votre abonnement vous accorde une licence limitee, non exclusive et non transferable pour utiliser le Service conformement aux presentes conditions.'
                : 'The Service, including its source code, design, features, and documentation, is the exclusive property of AutoERP. Your subscription grants you a limited, non-exclusive, non-transferable license to use the Service in accordance with these terms.'}
            </p>
          </section>

          {/* Section 6 */}
          <section>
            <h2 className={`text-xl font-semibold ${colorTokens.text.primary} ${colorTokens.text.darkInverse}`}>
              {isFrench ? '6. Disponibilite du service' : '6. Service Availability'}
            </h2>
            <p className={`mt-2 ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray300}`}>
              {isFrench
                ? 'Nous nous efforcons de maintenir une disponibilite elevee du Service, mais ne garantissons pas un fonctionnement ininterrompu. Des maintenances planifiees seront communiquees a l\'avance. Nous ne sommes pas responsables des interruptions resultant de facteurs hors de notre controle.'
                : 'We strive to maintain high availability of the Service but do not guarantee uninterrupted operation. Planned maintenance will be communicated in advance. We are not responsible for interruptions resulting from factors beyond our control.'}
            </p>
          </section>

          {/* Section 7 */}
          <section>
            <h2 className={`text-xl font-semibold ${colorTokens.text.primary} ${colorTokens.text.darkInverse}`}>
              {isFrench ? '7. Limitation de responsabilite' : '7. Limitation of Liability'}
            </h2>
            <p className={`mt-2 ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray300}`}>
              {isFrench
                ? 'Dans les limites autorisees par le droit francais, AutoERP ne sera pas responsable des dommages indirects, accessoires, speciaux ou consequents resultant de l\'utilisation ou de l\'impossibilite d\'utiliser le Service. Notre responsabilite totale est limitee au montant des frais d\'abonnement payes au cours des 12 mois precedant la reclamation.'
                : 'To the maximum extent permitted by French law, AutoERP shall not be liable for any indirect, incidental, special, or consequential damages arising out of or in connection with the use or inability to use the Service. Our total liability is limited to the amount of subscription fees paid in the 12 months preceding the claim.'}
            </p>
          </section>

          {/* Section 8 */}
          <section>
            <h2 className={`text-xl font-semibold ${colorTokens.text.primary} ${colorTokens.text.darkInverse}`}>
              {isFrench ? '8. Resiliation' : '8. Termination'}
            </h2>
            <p className={`mt-2 ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray300}`}>
              {isFrench
                ? 'Chaque partie peut resilier cet accord avec un preavis de 30 jours. Nous nous reservons le droit de suspendre ou de resilier votre acces en cas de violation des presentes conditions. En cas de resiliation, vous pouvez exporter vos donnees pendant une periode de 30 jours.'
                : 'Either party may terminate this agreement with 30 days written notice. We reserve the right to suspend or terminate your access in case of violation of these terms. Upon termination, you may export your data within a 30-day period.'}
            </p>
          </section>

          {/* Section 9 */}
          <section>
            <h2 className={`text-xl font-semibold ${colorTokens.text.primary} ${colorTokens.text.darkInverse}`}>
              {isFrench ? '9. Droit applicable et juridiction' : '9. Governing Law and Jurisdiction'}
            </h2>
            <p className={`mt-2 ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray300}`}>
              {isFrench
                ? 'Les presentes conditions sont regies par le droit francais. Tout litige decoulant de ou lie a ces conditions sera soumis a la competence exclusive des tribunaux de Paris, France.'
                : 'These terms are governed by and construed in accordance with the laws of France. Any dispute arising out of or relating to these terms shall be subject to the exclusive jurisdiction of the courts of Paris, France.'}
            </p>
          </section>

          {/* Section 10 */}
          <section>
            <h2 className={`text-xl font-semibold ${colorTokens.text.primary} ${colorTokens.text.darkInverse}`}>
              {isFrench ? '10. Contact' : '10. Contact'}
            </h2>
            <p className={`mt-2 ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray300}`}>
              {isFrench
                ? 'Pour toute question concernant ces conditions, contactez-nous a :'
                : 'For any questions regarding these terms, contact us at:'}
            </p>
            <p className={`mt-2 ${colorTokens.text.muted} ${colorTokens.variants.darkTextGray300}`}>
              <a
                href="mailto:privacy@company.com"
                className="text-primary-600 hover:text-primary-500 dark:text-primary-400"
              >
                privacy@company.com
              </a>
            </p>
          </section>
        </div>
      </div>
    </div>
  )
}
