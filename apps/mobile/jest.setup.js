// Mock AsyncStorage
jest.mock('@react-native-async-storage/async-storage', () => ({
  setItem: jest.fn(() => Promise.resolve()),
  getItem: jest.fn(() => Promise.resolve(null)),
  removeItem: jest.fn(() => Promise.resolve()),
  clear: jest.fn(() => Promise.resolve()),
}));

// Mock Expo SecureStore
jest.mock('expo-secure-store', () => ({
  setItemAsync: jest.fn(() => Promise.resolve()),
  getItemAsync: jest.fn(() => Promise.resolve(null)),
  deleteItemAsync: jest.fn(() => Promise.resolve()),
}));

// Mock NetInfo
jest.mock('@react-native-community/netinfo', () => ({
  useNetInfo: jest.fn(() => ({
    isConnected: true,
    type: 'wifi',
  })),
  addEventListener: jest.fn(() => jest.fn()),
}));

// Mock react-native-paper components
jest.mock('react-native-paper', () => {
  const React = require('react');
  const { View, Text } = require('react-native');

  return {
    ActivityIndicator: ({ size, color }: any) => React.createElement(View, { testID: 'activity-indicator' }),
    Text: ({ children, ...props }: any) => React.createElement(Text, props, children),
  };
});

// Mock lucide-react-native icons
jest.mock('lucide-react-native', () => ({
  WifiOff: () => null,
  CloudUpload: () => null,
}));
