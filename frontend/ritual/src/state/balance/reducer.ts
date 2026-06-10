import { createReducer } from '@reduxjs/toolkit';
import { updateUserBalance } from './actions';

export interface BalanceState {
  flag: boolean;
  blockNumber?: number;
}
const initialState: BalanceState = {
  flag: false,
};

export default createReducer(initialState, (builder) =>
  builder.addCase(updateUserBalance, (state, { payload: blockNumber }) => {
    return {
      ...state,
      flag: !state.flag,
      blockNumber,
    };
  }),
);
