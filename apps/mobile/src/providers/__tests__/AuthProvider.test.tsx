/**
 * AuthProvider Tests
 *
 * Critical regression tests for authentication functionality.
 * Ensures login, logout, and token management work correctly.
 */

import React from 'react';
import { renderHook, act, waitFor } from '@testing-library/react-native';
import { AuthProvider, useAuth } from '../AuthProvider';
import * as SecureStore from 'expo-secure-store';
import { api } from '@/lib/api';

// Mock dependencies
jest.mock('expo-secure-store');
jest.mock('@/lib/api');

const mockSecureStore = SecureStore as jest.Mocked<typeof SecureStore>;
const mockApi = api as jest.Mocked<typeof api>;

// Wrapper component for testing hooks
const wrapper = ({ children }: { children: React.ReactNode }) => (
  <AuthProvider>{children}</AuthProvider>
);

describe('AuthProvider', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  describe('Initial State', () => {
    it('should start with loading state', () => {
      mockSecureStore.getItemAsync.mockResolvedValue(null);

      const { result } = renderHook(() => useAuth(), { wrapper });

      expect(result.current.isLoading).toBe(true);
      expect(result.current.user).toBeNull();
      expect(result.current.isAuthenticated).toBe(false);
    });

    it('should check for existing token on mount', async () => {
      mockSecureStore.getItemAsync.mockResolvedValue(null);

      renderHook(() => useAuth(), { wrapper });

      await waitFor(() => {
        expect(mockSecureStore.getItemAsync).toHaveBeenCalledWith('auth_token');
      });
    });

    it('should load user if valid token exists', async () => {
      const mockUser = {
        id: 1,
        name: 'Test User',
        email: 'test@example.com',
        current_company_id: 1,
      };

      mockSecureStore.getItemAsync.mockResolvedValue('valid-token');
      mockApi.get.mockResolvedValue({
        data: { data: mockUser },
      });

      const { result } = renderHook(() => useAuth(), { wrapper });

      await waitFor(() => {
        expect(result.current.isLoading).toBe(false);
        expect(result.current.user).toEqual(mockUser);
        expect(result.current.isAuthenticated).toBe(true);
      });
    });

    it('should clear invalid token on mount', async () => {
      mockSecureStore.getItemAsync.mockResolvedValue('invalid-token');
      mockApi.get.mockRejectedValue(new Error('Unauthorized'));

      const { result } = renderHook(() => useAuth(), { wrapper });

      await waitFor(() => {
        expect(mockSecureStore.deleteItemAsync).toHaveBeenCalledWith('auth_token');
        expect(result.current.isLoading).toBe(false);
        expect(result.current.user).toBeNull();
      });
    });
  });

  describe('Login Functionality', () => {
    it('should successfully login with valid credentials', async () => {
      const mockUser = {
        id: 1,
        name: 'Test User',
        email: 'test@example.com',
        current_company_id: 1,
      };

      mockApi.post.mockResolvedValue({
        data: {
          data: {
            token: 'new-auth-token',
            user: mockUser,
          },
        },
      });

      mockSecureStore.getItemAsync.mockResolvedValue(null);

      const { result } = renderHook(() => useAuth(), { wrapper });

      await waitFor(() => {
        expect(result.current.isLoading).toBe(false);
      });

      await act(async () => {
        await result.current.login('test@example.com', 'password');
      });

      expect(mockApi.post).toHaveBeenCalledWith('/auth/login', {
        email: 'test@example.com',
        password: 'password',
      });
      expect(mockSecureStore.setItemAsync).toHaveBeenCalledWith(
        'auth_token',
        'new-auth-token'
      );
      expect(result.current.user).toEqual(mockUser);
      expect(result.current.isAuthenticated).toBe(true);
    });

    it('should throw error with network failure', async () => {
      // Reject with an error that has no message property (pure network failure)
      mockApi.post.mockRejectedValue({
        config: { baseURL: 'http://192.168.1.196:8002/api/v1' },
      });

      mockSecureStore.getItemAsync.mockResolvedValue(null);

      const { result } = renderHook(() => useAuth(), { wrapper });

      await waitFor(() => {
        expect(result.current.isLoading).toBe(false);
      });

      await expect(
        act(async () => {
          await result.current.login('test@example.com', 'wrong');
        })
      ).rejects.toThrow('Failed to login. Please check your network connection.');
    });

    it('should extract error message from API response', async () => {
      mockApi.post.mockRejectedValue({
        response: {
          status: 422,
          data: {
            errors: {
              email: ['The provided credentials are incorrect.'],
            },
          },
        },
      });

      mockSecureStore.getItemAsync.mockResolvedValue(null);

      const { result } = renderHook(() => useAuth(), { wrapper });

      await waitFor(() => {
        expect(result.current.isLoading).toBe(false);
      });

      await expect(
        act(async () => {
          await result.current.login('test@example.com', 'wrong');
        })
      ).rejects.toThrow('The provided credentials are incorrect.');
    });

    it('should log detailed error information to console', async () => {
      const consoleErrorSpy = jest.spyOn(console, 'error').mockImplementation();

      mockApi.post.mockRejectedValue({
        response: {
          status: 500,
          data: { message: 'Server error' },
        },
        message: 'Request failed',
        config: { baseURL: 'http://localhost:8002/api/v1' },
      });

      mockSecureStore.getItemAsync.mockResolvedValue(null);

      const { result } = renderHook(() => useAuth(), { wrapper });

      await waitFor(() => {
        expect(result.current.isLoading).toBe(false);
      });

      try {
        await act(async () => {
          await result.current.login('test@example.com', 'password');
        });
      } catch (e) {
        // Expected to throw
      }

      expect(consoleErrorSpy).toHaveBeenCalledWith(
        '[Auth] Login error:',
        expect.objectContaining({
          status: 500,
          url: 'http://localhost:8002/api/v1',
        })
      );

      consoleErrorSpy.mockRestore();
    });
  });

  describe('Logout Functionality', () => {
    it('should successfully logout', async () => {
      const mockUser = {
        id: 1,
        name: 'Test User',
        email: 'test@example.com',
        current_company_id: 1,
      };

      // Setup logged in state
      mockSecureStore.getItemAsync.mockResolvedValue('auth-token');
      mockApi.get.mockResolvedValue({
        data: { data: mockUser },
      });

      const { result } = renderHook(() => useAuth(), { wrapper });

      await waitFor(() => {
        expect(result.current.user).toEqual(mockUser);
      });

      mockApi.post.mockResolvedValue({ data: {} });

      await act(async () => {
        await result.current.logout();
      });

      expect(mockApi.post).toHaveBeenCalledWith('/auth/logout');
      expect(mockSecureStore.deleteItemAsync).toHaveBeenCalledWith('auth_token');
      expect(result.current.user).toBeNull();
      expect(result.current.isAuthenticated).toBe(false);
    });

    it('should clear token even if API call fails', async () => {
      mockSecureStore.getItemAsync.mockResolvedValue('auth-token');
      mockApi.get.mockResolvedValue({
        data: {
          data: {
            id: 1,
            name: 'Test',
            email: 'test@example.com',
            current_company_id: 1,
          },
        },
      });

      const { result } = renderHook(() => useAuth(), { wrapper });

      await waitFor(() => {
        expect(result.current.user).toBeDefined();
      });

      mockApi.post.mockRejectedValue(new Error('Network error'));

      await act(async () => {
        await result.current.logout();
      });

      expect(mockSecureStore.deleteItemAsync).toHaveBeenCalledWith('auth_token');
      expect(result.current.user).toBeNull();
    });
  });

  describe('Company Selection', () => {
    it('should switch company and update user', async () => {
      const mockUser = {
        id: 1,
        name: 'Test User',
        email: 'test@example.com',
        current_company_id: 1,
      };

      const updatedUser = {
        ...mockUser,
        current_company_id: 2,
      };

      mockSecureStore.getItemAsync.mockResolvedValue('auth-token');
      mockApi.get.mockResolvedValueOnce({
        data: { data: mockUser },
      });

      const { result } = renderHook(() => useAuth(), { wrapper });

      await waitFor(() => {
        expect(result.current.user).toEqual(mockUser);
      });

      mockApi.post.mockResolvedValue({ data: {} });
      mockApi.get.mockResolvedValueOnce({
        data: { data: updatedUser },
      });

      await act(async () => {
        await result.current.selectCompany(2);
      });

      expect(mockApi.post).toHaveBeenCalledWith('/user/switch-company', {
        company_id: 2,
      });
      expect(result.current.user?.current_company_id).toBe(2);
    });
  });

  describe('Security', () => {
    it('should store token in secure storage, not plain storage', async () => {
      mockApi.post.mockResolvedValue({
        data: {
          data: {
            token: 'secure-token',
            user: {
              id: 1,
              name: 'Test',
              email: 'test@example.com',
              current_company_id: 1,
            },
          },
        },
      });

      mockSecureStore.getItemAsync.mockResolvedValue(null);

      const { result } = renderHook(() => useAuth(), { wrapper });

      await waitFor(() => {
        expect(result.current.isLoading).toBe(false);
      });

      await act(async () => {
        await result.current.login('test@example.com', 'password');
      });

      // Verify SecureStore is used, not AsyncStorage
      expect(mockSecureStore.setItemAsync).toHaveBeenCalledWith(
        'auth_token',
        'secure-token'
      );
    });
  });

  describe('Error Handling', () => {
    it('should handle useAuth called outside provider', () => {
      expect(() => {
        renderHook(() => useAuth());
      }).toThrow('useAuth must be used within AuthProvider');
    });
  });
});
