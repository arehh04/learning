/**
 * SERVER ENTRY POINT (main.ts)
 * -------------------------------------------------------------
 * This is the file that boots up the server. It imports the configured
 * app instance from app.ts and starts listening on the specified port.
 */

import { app } from './app';

const PORT = process.env.PORT || 3000;

app.listen(PORT, () => {
  console.log('====================================================');
  console.log(`🚀 Server is running at: http://localhost:${PORT}`);
  console.log(`📖 View all users at:    http://localhost:${PORT}/users`);
  console.log('====================================================');
});
