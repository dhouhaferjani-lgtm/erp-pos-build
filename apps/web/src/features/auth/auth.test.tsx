import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, MemoryRouter, Routes, Route } from 'react-router-dom'
import { useAuthStore } from '../../stores/authStore'
import { LoginPage } from './LoginPage'
import { AuthProvider, RequireAuth } from './AuthProvider'

// Mock the API - must use vi.hoisted for variables used in vi.mock factory
const { mockApiGet, mockApiPost } = vi.hoisted(() => ({
  mockApiGet: vi.fn(),
  mockApiPost: vi.fn(),
}))

vi.mock('../../lib/api', () => ({
  api: {
    get: mockApiGet,
    post: mockApiPost,
  },
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}))

const createTestQueryClient = () =>
  new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

function TestWrapper({ children }: { children: React.ReactNode }) {
  return (
    <QueryClientProvider client={createTestQueryClient()}>
      <BrowserRouter>{children}</BrowserRouter>
    </QueryClientProvider>
  )
}

describe('Authentication', () => {
  beforeEach(() => {
    // Reset auth store before each test
    useAuthStore.getState().logout()
    vi.clearAllMocks()
  })

  describe('LoginPage', () => {
    it('renders login form with email and password fields', () => {
      render(<LoginPage />, { wrapper: TestWrapper })

      expect(screen.getByLabelText(/email/i)).toBeInTheDocument()
      expect(screen.getByLabelText(/password/i)).toBeInTheDocument()
      expect(screen.getByRole('button', { name: /sign in/i })).toBeInTheDocument()
    })

    it('shows validation errors for empty fields', async () => {
      const user = userEvent.setup()
      render(<LoginPage />, { wrapper: TestWrapper })

      const submitButton = screen.getByRole('button', { name: /sign in/i })
      await user.click(submitButton)

      await waitFor(() => {
        const errors = screen.getAllByText(/this field is required/i)
        expect(errors.length).toBeGreaterThan(0)
      })
    })

    it('prevents form submission with invalid email format', async () => {
      const user = userEvent.setup()

      render(<LoginPage />, { wrapper: TestWrapper })

      const emailInput = screen.getByLabelText(/email/i)
      const passwordInput = screen.getByLabelText(/password/i)
      // Use an email without proper domain format
      await user.type(emailInput, 'notanemail')
      await user.type(passwordInput, 'somepassword')

      const submitButton = screen.getByRole('button', { name: /sign in/i })
      await user.click(submitButton)

      // The form should NOT submit because email is invalid
      // Check that the API was never called
      expect(mockApiPost).not.toHaveBeenCalled()
    })

    it('disables submit button while loading', async () => {
      const user = userEvent.setup()
      mockApiPost.mockImplementation(
        () => new Promise((resolve) => setTimeout(resolve, 1000))
      )

      render(<LoginPage />, { wrapper: TestWrapper })

      const emailInput = screen.getByLabelText(/email/i)
      const passwordInput = screen.getByLabelText(/password/i)
      const submitButton = screen.getByRole('button', { name: /sign in/i })

      await user.type(emailInput, 'test@example.com')
      await user.type(passwordInput, 'password123')
      await user.click(submitButton)

      await waitFor(() => {
        expect(submitButton).toBeDisabled()
      })
    })

    it('displays error message on login failure', async () => {
      const user = userEvent.setup()
      mockApiPost.mockRejectedValue({
        response: {
          data: {
            error: {
              message: 'Invalid credentials',
            },
          },
        },
      })

      render(<LoginPage />, { wrapper: TestWrapper })

      const emailInput = screen.getByLabelText(/email/i)
      const passwordInput = screen.getByLabelText(/password/i)
      const submitButton = screen.getByRole('button', { name: /sign in/i })

      await user.type(emailInput, 'test@example.com')
      await user.type(passwordInput, 'wrongpassword')
      await user.click(submitButton)

      await waitFor(() => {
        expect(screen.getByText(/invalid credentials/i)).toBeInTheDocument()
      })
    })
  })

  describe('useAuthStore', () => {
    it('initializes with no user and isAuthenticated false', () => {
      const state = useAuthStore.getState()
      expect(state.user).toBeNull()
      expect(state.isAuthenticated).toBe(false)
    })

    it('sets user and updates isAuthenticated', () => {
      const testUser = {
        id: '123',
        name: 'Test User',
        email: 'test@example.com',
        tenant_id: 'tenant-1',
        roles: ['admin'],
        email_verified_at: null,
      }

      useAuthStore.getState().setUser(testUser)

      const state = useAuthStore.getState()
      expect(state.user).toEqual(testUser)
      expect(state.isAuthenticated).toBe(true)
    })

    it('clears user on logout', () => {
      const testUser = {
        id: '123',
        name: 'Test User',
        email: 'test@example.com',
        tenant_id: 'tenant-1',
        roles: ['admin'],
        email_verified_at: null,
      }

      useAuthStore.getState().setUser(testUser)
      useAuthStore.getState().logout()

      const state = useAuthStore.getState()
      expect(state.user).toBeNull()
      expect(state.isAuthenticated).toBe(false)
    })
  })

  describe('RequireAuth', () => {
    it('redirects to login when not authenticated', () => {
      const ProtectedContent = () => <div>Protected Content</div>

      render(
        <QueryClientProvider client={createTestQueryClient()}>
          <MemoryRouter initialEntries={['/dashboard']}>
            <Routes>
              <Route path="/login" element={<div>Login Page</div>} />
              <Route
                path="/dashboard"
                element={
                  <RequireAuth>
                    <ProtectedContent />
                  </RequireAuth>
                }
              />
            </Routes>
          </MemoryRouter>
        </QueryClientProvider>
      )

      expect(screen.getByText('Login Page')).toBeInTheDocument()
      expect(screen.queryByText('Protected Content')).not.toBeInTheDocument()
    })

    it('renders children when authenticated', () => {
      const testUser = {
        id: '123',
        name: 'Test User',
        email: 'test@example.com',
        tenant_id: 'tenant-1',
        roles: ['admin'],
        email_verified_at: null,
      }
      useAuthStore.getState().setUser(testUser)

      const ProtectedContent = () => <div>Protected Content</div>

      render(
        <QueryClientProvider client={createTestQueryClient()}>
          <MemoryRouter initialEntries={['/dashboard']}>
            <Routes>
              <Route path="/login" element={<div>Login Page</div>} />
              <Route
                path="/dashboard"
                element={
                  <RequireAuth>
                    <ProtectedContent />
                  </RequireAuth>
                }
              />
            </Routes>
          </MemoryRouter>
        </QueryClientProvider>
      )

      expect(screen.getByText('Protected Content')).toBeInTheDocument()
      expect(screen.queryByText('Login Page')).not.toBeInTheDocument()
    })
  })

  describe('AuthProvider', () => {
    it('checks for existing session on mount when token exists', async () => {
      // Set a token in the auth store first
      useAuthStore.getState().setAuth(
        {
          id: '123',
          name: 'Test User',
          email: 'test@example.com',
          tenant_id: 'tenant-1',
          roles: ['admin'],
          email_verified_at: null,
        },
        'test-token'
      )

      mockApiGet.mockResolvedValue({
        data: {
          data: {
            id: '123',
            name: 'Test User',
            email: 'test@example.com',
            tenantId: 'tenant-1',
            roles: ['admin'],
          },
        },
      })

      render(
        <QueryClientProvider client={createTestQueryClient()}>
          <BrowserRouter>
            <AuthProvider>
              <div>App Content</div>
            </AuthProvider>
          </BrowserRouter>
        </QueryClientProvider>
      )

      await waitFor(() => {
        expect(mockApiGet).toHaveBeenCalledWith('/auth/me')
      })
    })

    it('does not check session when no token exists', () => {
      // Ensure no token
      useAuthStore.getState().logout()

      render(
        <QueryClientProvider client={createTestQueryClient()}>
          <BrowserRouter>
            <AuthProvider>
              <div>App Content</div>
            </AuthProvider>
          </BrowserRouter>
        </QueryClientProvider>
      )

      expect(screen.getByText('App Content')).toBeInTheDocument()
      expect(mockApiGet).not.toHaveBeenCalled()
    })
  })
})
