import { useState } from 'react';
import { View, StyleSheet, Alert } from 'react-native';
import {
  Text,
  Button,
  IconButton,
  ActivityIndicator,
  Snackbar,
} from 'react-native-paper';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useDraftCountingStore } from '@/features/counting/store/draftCountingStore';
import { useDraftSync } from '@/features/counting/services/draftSyncService';
import {
  Flashlight,
  FlashlightOff,
  X,
  Keyboard,
  Check,
} from 'lucide-react-native';

export default function DraftBarcodeScannerScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();
  const localId = id; // Now using localId from URL
  const addProduct = useDraftCountingStore((state) => state.addProduct);
  const { syncAll } = useDraftSync();
  const [permission, requestPermission] = useCameraPermissions();
  const [torch, setTorch] = useState(false);
  const [scanned, setScanned] = useState(false);
  const [snackbarVisible, setSnackbarVisible] = useState(false);
  const [snackbarMessage, setSnackbarMessage] = useState('');
  const [snackbarType, setSnackbarType] = useState<'success' | 'error'>('success');
  const [isAdding, setIsAdding] = useState(false);

  const handleBarcodeScan = async ({ data }: { data: string }) => {
    if (scanned || isAdding) return;
    setScanned(true);
    setIsAdding(true);

    try {
      // Add product locally (works offline!)
      // Note: For now we just store the barcode, the sync service will resolve it
      addProduct(localId, data);

      // Show success message
      setSnackbarType('success');
      setSnackbarMessage(`Added product with barcode: ${data}`);
      setSnackbarVisible(true);

      // Trigger background sync (non-blocking)
      syncAll();

      // Allow scanning again after short delay
      setTimeout(() => {
        setScanned(false);
      }, 1500);
    } catch (error: any) {
      const errorMessage = error.message || 'Failed to add product';

      if (errorMessage.includes('already')) {
        setSnackbarType('error');
        setSnackbarMessage('Product already added');
        setSnackbarVisible(true);
        setTimeout(() => setScanned(false), 1500);
      } else {
        Alert.alert('Error', errorMessage, [
          { text: 'Try Again', onPress: () => setScanned(false) },
        ]);
      }
    } finally {
      setIsAdding(false);
    }
  };

  const handleManualEntry = () => {
    Alert.prompt(
      'Enter Barcode',
      'Type the barcode number manually',
      async (barcode) => {
        if (barcode && barcode.trim()) {
          await handleBarcodeScan({ data: barcode.trim() });
        }
      },
      'plain-text'
    );
  };

  if (!permission) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator size="large" />
      </View>
    );
  }

  if (!permission.granted) {
    return (
      <View style={styles.permissionContainer}>
        <Text variant="bodyLarge" style={styles.permissionText}>
          Camera permission is required to scan barcodes
        </Text>
        <Button mode="contained" onPress={requestPermission}>
          Grant Permission
        </Button>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <CameraView
        style={StyleSheet.absoluteFillObject}
        facing="back"
        enableTorch={torch}
        barcodeScannerSettings={{
          barcodeTypes: [
            'ean13',
            'ean8',
            'upc_a',
            'upc_e',
            'code128',
            'code39',
            'qr',
          ],
        }}
        onBarcodeScanned={scanned ? undefined : handleBarcodeScan}
      />

      {/* Scan Frame */}
      <View style={styles.overlay}>
        <View style={styles.scanFrame} />
        <Text style={styles.instructions}>
          {addProductMutation.isPending
            ? 'Adding product...'
            : scanned
            ? 'Product added!'
            : 'Scan product barcode to add'}
        </Text>
      </View>

      {/* Top Controls */}
      <View style={styles.topControls}>
        <IconButton
          icon={() => <X size={24} color="white" />}
          onPress={() => router.back()}
          style={styles.iconButton}
        />
        <IconButton
          icon={() =>
            torch ? (
              <FlashlightOff size={24} color="white" />
            ) : (
              <Flashlight size={24} color="white" />
            )
          }
          onPress={() => setTorch(!torch)}
          style={styles.iconButton}
        />
      </View>

      {/* Bottom Controls */}
      <View style={styles.bottomControls}>
        <Button
          mode="contained"
          icon={() => <Keyboard size={20} color="white" />}
          onPress={handleManualEntry}
          style={styles.manualButton}
        >
          Enter Manually
        </Button>

        <Button
          mode="contained"
          icon={() => <Check size={20} color="white" />}
          onPress={() => router.back()}
          style={styles.doneButton}
        >
          Done
        </Button>
      </View>

      <Snackbar
        visible={snackbarVisible}
        onDismiss={() => setSnackbarVisible(false)}
        duration={1500}
        style={
          snackbarType === 'success'
            ? { backgroundColor: '#10b981' }
            : { backgroundColor: '#ef4444' }
        }
      >
        {snackbarMessage}
      </Snackbar>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: 'black' },
  centered: { flex: 1, justifyContent: 'center', alignItems: 'center' },
  permissionContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: 16,
  },
  permissionText: { textAlign: 'center', marginBottom: 16 },
  overlay: {
    ...StyleSheet.absoluteFillObject,
    justifyContent: 'center',
    alignItems: 'center',
  },
  scanFrame: {
    width: 288,
    height: 288,
    borderWidth: 2,
    borderColor: 'white',
    borderRadius: 8,
  },
  instructions: {
    color: 'white',
    marginTop: 16,
    fontSize: 16,
    fontWeight: '500',
  },
  topControls: {
    position: 'absolute',
    top: 48,
    left: 0,
    right: 0,
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
  },
  iconButton: {
    backgroundColor: 'rgba(0, 0, 0, 0.3)',
  },
  bottomControls: {
    position: 'absolute',
    bottom: 48,
    left: 16,
    right: 16,
    gap: 12,
  },
  manualButton: {
    backgroundColor: 'rgba(0, 0, 0, 0.6)',
  },
  doneButton: {
    backgroundColor: '#10b981',
  },
});
