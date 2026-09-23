/**
 * USER CONTROLLER (HTTP & Routing Layer)
 * -------------------------------------------------------------
 * The controller handles HTTP requests, extracts parameters/body,
 * calls the appropriate methods on the UserService, and returns
 * standard HTTP responses with proper status codes.
 */

import { Router, Request, Response } from "express";
import { UserService } from "./user.service";

export const userRouter = Router();
const userService = new UserService();

/**
 * GET /users
 * Retrieve the list of all users
 */
userRouter.get("/", async (_req: Request, res: Response) => {
  try {
    const users = await userService.findAll();
    res.json({
      success: true,
      count: users.length,
      data: users,
    });
  } catch (error) {
    res.status(500).json({ success: false, message: "Internal server error" });
  }
});

/**
 * GET /users/:id
 * Retrieve a specific user by their ID
 */
userRouter.get("/:id", async (req: Request, res: Response) => {
  try {
    const id = parseInt(req.params.id, 10);
    if (isNaN(id)) {
      res
        .status(400)
        .json({ success: false, message: "Invalid ID. Must be a number." });
      return;
    }

    const user = await userService.findById(id);
    if (!user) {
      res
        .status(404)
        .json({ success: false, message: `User with ID ${id} not found.` });
      return;
    }

    res.json({ success: true, data: user });
  } catch (error) {
    res.status(500).json({ success: false, message: "Internal server error" });
  }
});

/**
 * POST /users
 * Create a new user
 */
userRouter.post("/", async (req: Request, res: Response) => {
  try {
    const { name, email, role } = req.body;

    // Basic input validation
    if (!name || !email) {
      res.status(400).json({
        success: false,
        message: 'Both "name" and "email" are required fields.',
      });
      return;
    }

    const newUser = await userService.create({ name, email, role });
    res.status(201).json({
      success: true,
      message: "User created successfully",
      data: newUser,
    });
  } catch (error) {
    res.status(500).json({ success: false, message: "Internal server error" });
  }
});

/**
 * PUT /users/:id
 * Update an existing user by ID
 */
userRouter.put("/:id", async (req: Request, res: Response) => {
  try {
    const id = parseInt(req.params.id, 10);
    if (isNaN(id)) {
      res
        .status(400)
        .json({ success: false, message: "Invalid ID. Must be a number." });
      return;
    }

    const updatedUser = await userService.update(id, req.body);
    if (!updatedUser) {
      res
        .status(404)
        .json({ success: false, message: `User with ID ${id} not found.` });
      return;
    }

    res.json({
      success: true,
      message: "User updated successfully",
      data: updatedUser,
    });
  } catch (error) {
    res.status(500).json({ success: false, message: "Internal server error" });
  }
});

/**
 * DELETE /users/:id
 * Remove a user by ID
 */
userRouter.delete("/:id", async (req: Request, res: Response) => {
  try {
    const id = parseInt(req.params.id, 10);
    if (isNaN(id)) {
      res
        .status(400)
        .json({ success: false, message: "Invalid ID. Must be a number." });
      return;
    }

    const deleted = await userService.delete(id);
    if (!deleted) {
      res
        .status(404)
        .json({ success: false, message: `User with ID ${id} not found.` });
      return;
    }

    res.json({
      success: true,
      message: `User with ID ${id} has been deleted.`,
    });
  } catch (error) {
    res.status(500).json({ success: false, message: "Internal server error" });
  }
});
