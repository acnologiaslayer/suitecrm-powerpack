/**
 * SuiteCRM PowerPack WebSocket Notification Server
 *
 * Polls MySQL queue and pushes notifications to connected browser clients.
 * Runs embedded in the SuiteCRM container.
 */

const WebSocket = require('ws');
const http = require('http');
const mysql = require('mysql2/promise');
const crypto = require('crypto');

// Configuration from environment variables
const config = {
    port: parseInt(process.env.WS_PORT) || 3001,
    pollInterval: parseInt(process.env.POLL_INTERVAL) || 2000,
    jwtSecret: process.env.NOTIFICATION_JWT_SECRET || process.env.JWT_SECRET || 'default-secret-change-me',
    mysql: {
        host: process.env.SUITECRM_DATABASE_HOST || process.env.DB_HOST || 'localhost',
        port: parseInt(process.env.SUITECRM_DATABASE_PORT_NUMBER || process.env.DB_PORT) || 3306,
        user: process.env.SUITECRM_DATABASE_USER || process.env.DB_USER || 'suitecrm',
        password: process.env.SUITECRM_DATABASE_PASSWORD || process.env.DB_PASSWORD || '',
        database: process.env.SUITECRM_DATABASE_NAME || process.env.DB_NAME || 'suitecrm',
        connectionLimit: 5,
        waitForConnections: true,
        queueLimit: 0
    }
};

// MySQL connection pool
let pool = null;

// Map of userId -> Set of WebSocket connections
const userConnections = new Map();

// Stats
let stats = {
    totalConnections: 0,
    totalNotificationsSent: 0,
    startTime: new Date()
};

/**
 * Initialize MySQL connection pool
 */
async function initDatabase() {
    try {
        pool = mysql.createPool(config.mysql);

        // Test connection
        const conn = await pool.getConnection();
        console.log('[DB] Connected to MySQL successfully');
        conn.release();

        return true;
    } catch (error) {
        console.error('[DB] Failed to connect to MySQL:', error.message);
        return false;
    }
}

/**
 * Create HTTP server for health checks and WebSocket upgrade
 */
function createServer() {
    const server = http.createServer((req, res) => {
        // Health check endpoint
        if (req.url === '/health') {
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({
                status: 'ok',
                uptime: Math.floor((Date.now() - stats.startTime) / 1000),
                connections: userConnections.size,
                totalConnections: stats.totalConnections,
                notificationsSent: stats.totalNotificationsSent
            }));
            return;
        }

        // Stats endpoint
        if (req.url === '/stats') {
            const connectedUsers = [];
            userConnections.forEach((connections, userId) => {
                connectedUsers.push({ userId, connectionCount: connections.size });
            });

            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({
                uptime: Math.floor((Date.now() - stats.startTime) / 1000),
                connectedUsers,
                stats
            }));
            return;
        }

        // Default: 404
        res.writeHead(404);
        res.end('Not Found');
    });

    // Create WebSocket server attached to HTTP server
    const wss = new WebSocket.Server({ server });

    wss.on('connection', handleConnection);

    server.listen(config.port, '0.0.0.0', () => {
        console.log(`[WS] WebSocket server listening on port ${config.port}`);
        console.log(`[WS] Health check: http://localhost:${config.port}/health`);
    });

    return wss;
}

/**
 * Handle new WebSocket connection
 */
async function handleConnection(ws, req) {
    const clientIp = req.headers['x-forwarded-for'] || req.socket.remoteAddress;
    console.log(`[WS] New connection from ${clientIp}`);

    stats.totalConnections++;

    // Connection state
    ws.isAlive = true;
    ws.userId = null;
    ws.authenticated = false;

    // Heartbeat handler
    ws.on('pong', () => {
        ws.isAlive = true;
    });

    // Message handler
    ws.on('message', async (message) => {
        try {
            const data = JSON.parse(message.toString());

            switch (data.type) {
                case 'auth':
                    await handleAuth(ws, data.token);
                    break;
                case 'ack':
                    await handleAck(ws, data.notificationId);
                    break;
                case 'ping':
                    ws.send(JSON.stringify({ type: 'pong' }));
                    break;
                default:
                    console.log(`[WS] Unknown message type: ${data.type}`);
            }
        } catch (error) {
            console.error('[WS] Message parse error:', error.message);
            ws.send(JSON.stringify({ type: 'error', message: 'Invalid message format' }));
        }
    });

    // Disconnect handler
    ws.on('close', () => {
        if (ws.userId) {
            const connections = userConnections.get(ws.userId);
            if (connections) {
                connections.delete(ws);
                if (connections.size === 0) {
                    userConnections.delete(ws.userId);
                }
            }
            console.log(`[WS] User ${ws.userId} disconnected (${userConnections.size} users online)`);
        }
    });

    // Error handler
    ws.on('error', (error) => {
        console.error('[WS] Connection error:', error.message);
    });

    // Send authentication challenge
    ws.send(JSON.stringify({ type: 'auth_required' }));
}

/**
 * Handle authentication message
 */
async function handleAuth(ws, token) {
    if (!token) {
        ws.send(JSON.stringify({ type: 'auth_failed', error: 'No token provided' }));
        return;
    }

    const userId = validateJwtToken(token);

    if (!userId) {
        ws.send(JSON.stringify({ type: 'auth_failed', error: 'Invalid or expired token' }));
        ws.close(4001, 'Authentication failed');
        return;
    }

    ws.userId = userId;
    ws.authenticated = true;

    // Register connection
    if (!userConnections.has(userId)) {
        userConnections.set(userId, new Set());
    }
    userConnections.get(userId).add(ws);

    console.log(`[WS] User ${userId} authenticated (${userConnections.size} users online)`);

    ws.send(JSON.stringify({
        type: 'auth_success',
        userId: userId
    }));

    // Send any pending notifications immediately
    await sendPendingNotifications(userId);
}

/**
 * Handle notification acknowledgment
 */
async function handleAck(ws, notificationId) {
    if (!ws.authenticated || !ws.userId || !notificationId) {
        return;
    }

    try {
        await pool.execute(
            `UPDATE notification_queue
             SET status = 'acknowledged', acknowledged_at = NOW()
             WHERE id = ? AND user_id = ?`,
            [notificationId, ws.userId]
        );
    } catch (error) {
        console.error('[DB] Ack error:', error.message);
    }
}

/**
 * Validate JWT token and extract user ID
 */
function validateJwtToken(token) {
    try {
        const parts = token.split('.');
        if (parts.length !== 3) {
            return null;
        }

        const [header, payload, signature] = parts;

        // Verify signature
        const expectedSig = crypto
            .createHmac('sha256', config.jwtSecret)
            .update(`${header}.${payload}`)
            .digest('base64')
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=+$/, '');

        if (signature !== expectedSig) {
            return null;
        }

        // Decode payload
        const payloadData = JSON.parse(
            Buffer.from(payload.replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString()
        );

        // Check expiration
        if (payloadData.exp && payloadData.exp < Math.floor(Date.now() / 1000)) {
            return null;
        }

        return payloadData.userId || null;
    } catch (error) {
        console.error('[JWT] Validation error:', error.message);
        return null;
    }
}

/**
 * Send pending notifications for a specific user
 */
async function sendPendingNotifications(userId) {
    const connections = userConnections.get(userId);
    if (!connections || connections.size === 0) {
        return;
    }

    try {
        const [rows] = await pool.execute(
            `SELECT id, payload, created_at
             FROM notification_queue
             WHERE user_id = ? AND status = 'pending'
             ORDER BY created_at ASC
             LIMIT 50`,
            [userId]
        );

        for (const row of rows) {
            const payload = JSON.parse(row.payload);
            const message = JSON.stringify({
                type: 'notification',
                id: row.id,
                ...payload,
                timestamp: row.created_at
            });

            let sent = false;
            for (const ws of connections) {
                if (ws.readyState === WebSocket.OPEN) {
                    ws.send(message);
                    sent = true;
                }
            }

            if (sent) {
                await pool.execute(
                    `UPDATE notification_queue SET status = 'sent', sent_at = NOW() WHERE id = ?`,
                    [row.id]
                );
                stats.totalNotificationsSent++;
            }
        }
    } catch (error) {
        console.error('[Poll] Error sending pending notifications:', error.message);
    }
}

/**
 * Poll database and deliver notifications to connected users
 */
async function pollAndDeliver() {
    const connectedUserIds = Array.from(userConnections.keys());

    if (connectedUserIds.length === 0) {
        return;
    }

    try {
        // Query pending notifications for connected users
        const placeholders = connectedUserIds.map(() => '?').join(',');
        const [rows] = await pool.execute(
            `SELECT id, user_id, payload, created_at
             FROM notification_queue
             WHERE status = 'pending'
             AND user_id IN (${placeholders})
             ORDER BY created_at ASC
             LIMIT 100`,
            connectedUserIds
        );

        for (const row of rows) {
            const connections = userConnections.get(row.user_id);
            if (!connections || connections.size === 0) {
                continue;
            }

            const payload = JSON.parse(row.payload);
            const message = JSON.stringify({
                type: 'notification',
                id: row.id,
                ...payload,
                timestamp: row.created_at
            });

            let sent = false;
            for (const ws of connections) {
                if (ws.readyState === WebSocket.OPEN) {
                    ws.send(message);
                    sent = true;
                }
            }

            if (sent) {
                await pool.execute(
                    `UPDATE notification_queue SET status = 'sent', sent_at = NOW() WHERE id = ?`,
                    [row.id]
                );
                stats.totalNotificationsSent++;
            }
        }
    } catch (error) {
        console.error('[Poll] Error:', error.message);
    }
}

/**
 * Heartbeat to detect dead connections
 */
function startHeartbeat(wss) {
    setInterval(() => {
        wss.clients.forEach((ws) => {
            if (ws.isAlive === false) {
                console.log('[WS] Terminating dead connection');
                return ws.terminate();
            }
            ws.isAlive = false;
            ws.ping();
        });
    }, 30000);
}

/**
 * Cleanup old notification queue entries periodically
 */
async function cleanupOldNotifications() {
    try {
        const [result] = await pool.execute(
            `DELETE FROM notification_queue WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)`
        );
        if (result.affectedRows > 0) {
            console.log(`[Cleanup] Removed ${result.affectedRows} old notifications`);
        }
    } catch (error) {
        console.error('[Cleanup] Error:', error.message);
    }
}

/**
 * Main entry point
 */
async function main() {
    console.log('='.repeat(50));
    console.log('SuiteCRM PowerPack WebSocket Notification Server');
    console.log('='.repeat(50));
    console.log(`Port: ${config.port}`);
    console.log(`Poll Interval: ${config.pollInterval}ms`);
    console.log(`Database Host: ${config.mysql.host}`);
    console.log('='.repeat(50));

    // Initialize database
    let dbReady = false;
    let retries = 0;
    const maxRetries = 30;

    while (!dbReady && retries < maxRetries) {
        dbReady = await initDatabase();
        if (!dbReady) {
            retries++;
            console.log(`[DB] Retrying in 2 seconds... (${retries}/${maxRetries})`);
            await new Promise(resolve => setTimeout(resolve, 2000));
        }
    }

    if (!dbReady) {
        console.error('[DB] Could not connect to database after multiple retries');
        process.exit(1);
    }

    // Create WebSocket server
    const wss = createServer();

    // Start heartbeat
    startHeartbeat(wss);

    // Start polling
    setInterval(pollAndDeliver, config.pollInterval);
    console.log(`[Poll] Started with ${config.pollInterval}ms interval`);

    // Start cleanup (run once per hour)
    setInterval(cleanupOldNotifications, 3600000);

    // Graceful shutdown
    process.on('SIGTERM', async () => {
        console.log('[Server] Received SIGTERM, shutting down...');

        wss.clients.forEach((ws) => {
            ws.close(1001, 'Server shutting down');
        });

        if (pool) {
            await pool.end();
        }

        process.exit(0);
    });

    process.on('SIGINT', async () => {
        console.log('[Server] Received SIGINT, shutting down...');
        process.exit(0);
    });
}

// Run main
main().catch((error) => {
    console.error('[Fatal] Unexpected error:', error);
    process.exit(1);
});
