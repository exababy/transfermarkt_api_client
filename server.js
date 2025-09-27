import express from 'express';
import cors from 'cors';
import helmet from 'helmet';
import * as services from './src/services/index.js';

const app = express();
const PORT = process.env.PORT || 3000;

// Middleware
app.use(helmet()); // Security headers
app.use(cors()); // Enable CORS
app.use(express.json()); // Parse JSON bodies

// Root endpoint
app.get('/', (req, res) => {
  res.json({
    message: 'Transfermarkt API Client',
    version: '1.0.0',
    endpoints: [
      'GET /player/:id - Get player profile',
      'GET /club/:id - Get club information',
      'GET /competition/:code - Get competition data',
      'GET /game/:id - Get game details',
      'GET /referee/:id - Get referee profile',
      'GET /coach/:id - Get coach profile',
      'GET /stadium/:id - Get stadium information'
    ],
    examples: [
      '/player/28003 - Lionel Messi profile',
      '/club/4 - FC Barcelona information',
      '/competition/GB1 - Premier League data'
    ]
  });
});

// Player endpoint
app.get('/player/:id', async (req, res) => {
  try {
    const playerId = parseInt(req.params.id);
    if (isNaN(playerId)) {
      return res.status(400).json({ error: 'Invalid player ID' });
    }

    const data = await services.playerProfile(playerId);
    if (!data) {
      return res.status(404).json({ error: 'Player not found' });
    }

    res.json(data);
  } catch (error) {
    console.error('Error fetching player:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

// Club endpoint
app.get('/club/:id', async (req, res) => {
  try {
    const clubId = parseInt(req.params.id);
    if (isNaN(clubId)) {
      return res.status(400).json({ error: 'Invalid club ID' });
    }

    const data = await services.clubInfo(clubId);
    if (!data) {
      return res.status(404).json({ error: 'Club not found' });
    }

    res.json(data);
  } catch (error) {
    console.error('Error fetching club:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

// Competition endpoint
app.get('/competition/:code', async (req, res) => {
  try {
    const competitionCode = req.params.code;
    
    const data = await services.competitionTable(null, competitionCode);
    if (!data) {
      return res.status(404).json({ error: 'Competition not found' });
    }

    res.json(data);
  } catch (error) {
    console.error('Error fetching competition:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

// Game endpoint
app.get('/game/:id', async (req, res) => {
  try {
    const gameId = parseInt(req.params.id);
    if (isNaN(gameId)) {
      return res.status(400).json({ error: 'Invalid game ID' });
    }

    const data = await services.gameDetails(gameId);
    if (!data) {
      return res.status(404).json({ error: 'Game not found' });
    }

    res.json(data);
  } catch (error) {
    console.error('Error fetching game:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

// Referee endpoint
app.get('/referee/:id', async (req, res) => {
  try {
    const refereeId = parseInt(req.params.id);
    if (isNaN(refereeId)) {
      return res.status(400).json({ error: 'Invalid referee ID' });
    }

    const data = await services.refereeProfile(refereeId);
    if (!data) {
      return res.status(404).json({ error: 'Referee not found' });
    }

    res.json(data);
  } catch (error) {
    console.error('Error fetching referee:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

// Coach endpoint
app.get('/coach/:id', async (req, res) => {
  try {
    const coachId = parseInt(req.params.id);
    if (isNaN(coachId)) {
      return res.status(400).json({ error: 'Invalid coach ID' });
    }

    const data = await services.coachProfile(coachId);
    if (!data) {
      return res.status(404).json({ error: 'Coach not found' });
    }

    res.json(data);
  } catch (error) {
    console.error('Error fetching coach:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

// Stadium endpoint
app.get('/stadium/:id', async (req, res) => {
  try {
    const stadiumId = parseInt(req.params.id);
    if (isNaN(stadiumId)) {
      return res.status(400).json({ error: 'Invalid stadium ID' });
    }

    const data = await services.stadiumInfo(stadiumId);
    if (!data) {
      return res.status(404).json({ error: 'Stadium not found' });
    }

    res.json(data);
  } catch (error) {
    console.error('Error fetching stadium:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

// Health check endpoint
app.get('/health', (req, res) => {
  res.json({ status: 'OK', timestamp: new Date().toISOString() });
});

// 404 handler
app.use((req, res) => {
  res.status(404).json({ error: 'Endpoint not found' });
});

// Error handler
app.use((error, req, res, next) => {
  console.error('Unhandled error:', error);
  res.status(500).json({ error: 'Internal server error' });
});

// Start server
app.listen(PORT, () => {
  console.log(`🚀 Transfermarkt API Server running on port ${PORT}`);
  console.log(`📋 API Documentation: http://localhost:${PORT}/`);
});

export default app;