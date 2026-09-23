/**
 * USER SERVICE (Business Logic & Data Layer)
 * -------------------------------------------------------------
 * The service is responsible for handling all data operations
 * and business logic. Controllers should delegate data fetching,
 * creation, updates, and deletions to this service.
 */

// 1. Define the User data structure
export interface User {
  id: number;
  name: string;
  email: string;
  role: 'admin' | 'member';
  createdAt: Date;
}

// 2. Data Transfer Objects (DTOs) for incoming payloads
export interface CreateUserDto {
  name: string;
  email: string;
  role?: 'admin' | 'member';
}

export interface UpdateUserDto {
  name?: string;
  email?: string;
  role?: 'admin' | 'member';
}

export class UserService {
  // In-memory array acting as our temporary database
  private users: User[] = [
    {
      id: 1,
      name: 'Alice Johnson',
      email: 'alice@example.com',
      role: 'admin',
      createdAt: new Date('2026-01-10T10:00:00Z'),
    },
    {
      id: 2,
      name: 'Bob Smith',
      email: 'bob@example.com',
      role: 'member',
      createdAt: new Date('2026-02-15T14:30:00Z'),
    },
  ];

  // Auto-increment ID counter
  private nextId = 3;

  /**
   * Retrieve all users
   */
  async findAll(): Promise<User[]> {
    return this.users;
  }

  /**
   * Find a single user by their numeric ID
   */
  async findById(id: number): Promise<User | undefined> {
    return this.users.find((user) => user.id === id);
  }

  /**
   * Create a new user and add to storage
   */
  async create(dto: CreateUserDto): Promise<User> {
    const newUser: User = {
      id: this.nextId++,
      name: dto.name,
      email: dto.email,
      role: dto.role || 'member',
      createdAt: new Date(),
    };

    this.users.push(newUser);
    return newUser;
  }

  /**
   * Update an existing user by ID
   */
  async update(id: number, dto: UpdateUserDto): Promise<User | undefined> {
    const user = await this.findById(id);
    if (!user) {
      return undefined;
    }

    if (dto.name !== undefined) user.name = dto.name;
    if (dto.email !== undefined) user.email = dto.email;
    if (dto.role !== undefined) user.role = dto.role;

    return user;
  }

  /**
   * Delete a user by ID
   */
  async delete(id: number): Promise<boolean> {
    const index = this.users.findIndex((user) => user.id === id);
    if (index === -1) {
      return false;
    }

    this.users.splice(index, 1);
    return true;
  }
}
