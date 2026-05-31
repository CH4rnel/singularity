import { createAction } from '@reduxjs/toolkit';

export const updateUserBalance = createAction<number | undefined>(
  'balance/updateUserBalance',
);
