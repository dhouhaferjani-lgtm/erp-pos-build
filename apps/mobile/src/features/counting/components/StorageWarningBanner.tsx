import { useState } from 'react';
import { View, StyleSheet } from 'react-native';
import { Text, IconButton, ProgressBar } from 'react-native-paper';
import { AlertTriangle, AlertCircle, X } from 'lucide-react-native';
import { useStorageLimits } from '../services/storageLimits';

interface StorageWarningBannerProps {
  /**
   * Show detailed storage metrics (usage percentages)
   */
  showDetails?: boolean;

  /**
   * Allow dismissing warnings (errors cannot be dismissed)
   */
  dismissible?: boolean;
}

export function StorageWarningBanner({
  showDetails = false,
  dismissible = true,
}: StorageWarningBannerProps) {
  const { usage, warnings, health } = useStorageLimits();
  const [dismissed, setDismissed] = useState(false);

  // No warnings to show
  if (warnings.length === 0) {
    return null;
  }

  // User dismissed and it's only warnings (not errors)
  const hasErrors = warnings.some(w => w.type === 'error');
  if (dismissed && !hasErrors && dismissible) {
    return null;
  }

  // Get the most severe warning
  const primaryWarning = warnings[0];
  const isError = primaryWarning.type === 'error';

  // Color scheme based on severity
  const colors = isError
    ? {
        background: '#fee2e2',
        border: '#ef4444',
        text: '#991b1b',
        icon: '#dc2626',
      }
    : {
        background: '#fef3c7',
        border: '#f59e0b',
        text: '#b45309',
        icon: '#f59e0b',
      };

  return (
    <View
      style={[
        styles.banner,
        {
          backgroundColor: colors.background,
          borderColor: colors.border,
        },
      ]}
    >
      {/* Icon */}
      <View style={styles.iconContainer}>
        {isError ? (
          <AlertCircle size={24} color={colors.icon} />
        ) : (
          <AlertTriangle size={24} color={colors.icon} />
        )}
      </View>

      {/* Content */}
      <View style={styles.content}>
        <View style={styles.header}>
          <Text
            variant="titleSmall"
            style={[styles.title, { color: colors.text }]}
          >
            {isError ? 'Storage Limit Reached' : 'Storage Warning'}
          </Text>
          {dismissible && !isError && (
            <IconButton
              icon={() => <X size={20} color={colors.text} />}
              size={20}
              onPress={() => setDismissed(true)}
              style={styles.dismissButton}
            />
          )}
        </View>

        <Text
          variant="bodySmall"
          style={[styles.message, { color: colors.text }]}
        >
          {primaryWarning.message}
        </Text>

        {primaryWarning.action && (
          <Text
            variant="bodySmall"
            style={[styles.action, { color: colors.text }]}
          >
            → {primaryWarning.action}
          </Text>
        )}

        {/* Additional warnings */}
        {warnings.length > 1 && (
          <Text
            variant="bodySmall"
            style={[styles.additionalWarnings, { color: colors.text }]}
          >
            +{warnings.length - 1} more issue{warnings.length > 2 ? 's' : ''}
          </Text>
        )}

        {/* Detailed metrics */}
        {showDetails && (
          <View style={styles.details}>
            <View style={styles.metric}>
              <Text
                variant="bodySmall"
                style={[styles.metricLabel, { color: colors.text }]}
              >
                Drafts: {usage.drafts.count}/{usage.drafts.limit}
              </Text>
              <ProgressBar
                progress={usage.drafts.usage}
                color={colors.icon}
                style={styles.progressBar}
              />
            </View>

            <View style={styles.metric}>
              <Text
                variant="bodySmall"
                style={[styles.metricLabel, { color: colors.text }]}
              >
                Max products in draft: {usage.productsPerDraft.max}/
                {usage.productsPerDraft.limit}
              </Text>
              <ProgressBar
                progress={usage.productsPerDraft.usage}
                color={colors.icon}
                style={styles.progressBar}
              />
            </View>

            <View style={styles.metric}>
              <Text
                variant="bodySmall"
                style={[styles.metricLabel, { color: colors.text }]}
              >
                Pending ops: {usage.pendingOperations.count}/
                {usage.pendingOperations.limit}
              </Text>
              <ProgressBar
                progress={usage.pendingOperations.usage}
                color={colors.icon}
                style={styles.progressBar}
              />
            </View>

            <Text
              variant="bodySmall"
              style={[styles.storageSize, { color: colors.text }]}
            >
              Estimated: {usage.estimatedSize.megabytes.toFixed(2)} MB
            </Text>
          </View>
        )}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  banner: {
    flexDirection: 'row',
    padding: 12,
    marginHorizontal: 16,
    marginVertical: 8,
    borderRadius: 8,
    borderWidth: 1,
  },
  iconContainer: {
    marginRight: 12,
    marginTop: 2,
  },
  content: {
    flex: 1,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 4,
  },
  title: {
    fontWeight: '600',
    flex: 1,
  },
  dismissButton: {
    margin: 0,
    marginRight: -8,
  },
  message: {
    marginBottom: 4,
    lineHeight: 18,
  },
  action: {
    fontWeight: '500',
    marginTop: 4,
  },
  additionalWarnings: {
    marginTop: 8,
    fontStyle: 'italic',
  },
  details: {
    marginTop: 12,
    paddingTop: 12,
    borderTopWidth: 1,
    borderTopColor: 'rgba(0, 0, 0, 0.1)',
  },
  metric: {
    marginBottom: 8,
  },
  metricLabel: {
    marginBottom: 4,
    fontSize: 11,
  },
  progressBar: {
    height: 4,
    borderRadius: 2,
  },
  storageSize: {
    marginTop: 8,
    fontSize: 11,
    fontWeight: '500',
  },
});
