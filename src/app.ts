/**
 * APPLICATION CONFIGURATION (app.ts)
 * -------------------------------------------------------------
 * Configures the Express app, attaches global middleware, and mounts
 * route handlers. Keeping app configuration separate from server startup
 * (main.ts) makes it much easier to test with tools like Supertest.
 */

import express, { Request, Response, NextFunction } from "express";
import { userRouter } from "./users/user.controller";

export const app = express();

// 1. Built-in middleware to parse JSON bodies
app.use(express.json());

// 2. Simple logger middleware so your friend can see requests in the terminal
app.use((req: Request, _res: Response, next: NextFunction) => {
  console.log(`[${new Date().toISOString()}] ${req.method} ${req.originalUrl}`);
  next();
});

// 3. Welcome / Health check route
app.get("/", (_req: Request, res: Response) => {
  res.json({
    message: "Welcome to the Learning REST API!",
    availableEndpoints: {
      getAllUsers: "GET    /users",
      getUserById: "GET    /users/:id",
      createUser: "POST   /users",
      updateUser: "PUT    /users/:id",
      deleteUser: "DELETE /users/:id",
    },
  });
});

// 4. Mount the User routes under /users
app.use("/users", userRouter);

// 5. Catch-all 404 handler for unmatched routes
app.use((_req: Request, res: Response) => {
  res.status(404).json({
    success: false,
    message: "Endpoint not found. Check GET / for available endpoints.",
  });
});
