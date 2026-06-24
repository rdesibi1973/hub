-- Add 'Transfer' to movements.movement_type ENUM
ALTER TABLE movements
  MODIFY COLUMN movement_type ENUM('Arrival','Departure','Transfer') NOT NULL DEFAULT 'Arrival';
