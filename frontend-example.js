/**
 * Example code for frontend to communicate with the Laravel backend
 * Add this to your frontend project as a reference
 */

// Configuration
const API_URL = process.env.NODE_ENV === 'production'
  ? 'https://api.yourdomain.com'
  : 'http://localhost:8000';

// Axios instance with proper configuration
const api = axios.create({
  baseURL: API_URL,
  withCredentials: true, // Important for cookies/authentication
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  }
});

/**
 * Get CSRF cookie for authentication
 */
const getCsrfCookie = async () => {
  try {
    await api.get('/sanctum/csrf-cookie');
    console.log('CSRF cookie set');
  } catch (error) {
    console.error('Error getting CSRF cookie:', error);
  }
};

/**
 * Login user
 * @param {string} email
 * @param {string} password
 */
const login = async (email, password) => {
  try {
    // First get CSRF cookie
    await getCsrfCookie();

    // Then login
    const response = await api.post('/api/login', {
      email,
      password
    });

    return response.data;
  } catch (error) {
    console.error('Login error:', error);
    throw error;
  }
};

/**
 * Get authenticated user
 */
const getUser = async () => {
  try {
    const response = await api.get('/api/user');
    return response.data;
  } catch (error) {
    console.error('Error getting user:', error);
    throw error;
  }
};

/**
 * Fetch all events
 */
const getEvents = async () => {
  try {
    const response = await api.get('/api/events');
    return response.data;
  } catch (error) {
    console.error('Error fetching events:', error);
    throw error;
  }
};

/**
 * Create a new event (requires authentication)
 * @param {Object} eventData
 */
const createEvent = async (eventData) => {
  try {
    const response = await api.post('/api/events', eventData);
    return response.data;
  } catch (error) {
    console.error('Error creating event:', error);
    throw error;
  }
};

/**
 * Logout user
 */
const logout = async () => {
  try {
    await api.post('/api/logout');
    console.log('Logged out successfully');
  } catch (error) {
    console.error('Logout error:', error);
    throw error;
  }
};

// Usage example in React component:
/*
import React, { useEffect, useState } from 'react';
import { login, getEvents, createEvent } from './api'; // This file

function App() {
  const [user, setUser] = useState(null);
  const [events, setEvents] = useState([]);

  useEffect(() => {
    // Check if user is logged in
    getUser()
      .then(userData => setUser(userData))
      .catch(() => setUser(null));

    // Get events
    getEvents()
      .then(eventsData => setEvents(eventsData));
  }, []);

  const handleLogin = async (e) => {
    e.preventDefault();
    try {
      const userData = await login('user@example.com', 'password');
      setUser(userData);
    } catch (error) {
      // Handle error
    }
  };

  return (
    <div>
      {user ? (
        <div>
          <h1>Welcome, {user.name}</h1>
          <button onClick={logout}>Logout</button>
        </div>
      ) : (
        <form onSubmit={handleLogin}>
          <button type="submit">Login</button>
        </form>
      )}

      <h2>Events</h2>
      <ul>
        {events.map(event => (
          <li key={event.id}>{event.name}</li>
        ))}
      </ul>
    </div>
  );
}
*/
