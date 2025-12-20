const React = require('react');

// Mock React Native core modules
const mockComponent = (name) => {
  return ({ children, ...props }) => React.createElement(name, props, children);
};

const StyleSheet = {
  create: (styles) => styles,
  flatten: (styles) => styles,
  hairlineWidth: 1,
  absoluteFill: 0,
};

const View = mockComponent('View');
const Text = mockComponent('Text');
const TextInput = mockComponent('TextInput');
const TouchableOpacity = mockComponent('TouchableOpacity');
const ScrollView = mockComponent('ScrollView');
const FlatList = mockComponent('FlatList');
const Image = mockComponent('Image');
const ActivityIndicator = mockComponent('ActivityIndicator');

const Dimensions = {
  get: jest.fn(() => ({ width: 375, height: 667 })),
  addEventListener: jest.fn(),
  removeEventListener: jest.fn(),
};

const Platform = {
  OS: 'ios',
  Version: '14.0',
  select: (obj) => obj.ios || obj.default,
};

const AppState = {
  currentState: 'active',
  addEventListener: jest.fn(() => ({ remove: jest.fn() })),
  removeEventListener: jest.fn(),
};

const Alert = {
  alert: jest.fn(),
};

module.exports = {
  StyleSheet,
  View,
  Text,
  TextInput,
  TouchableOpacity,
  ScrollView,
  FlatList,
  Image,
  ActivityIndicator,
  Dimensions,
  Platform,
  AppState,
  Alert,
};
