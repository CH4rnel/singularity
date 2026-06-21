import React from 'react';
import { PageLayout } from 'layouts';
import { Container, Typography } from '@material-ui/core';

const WhaleChatPage: React.FC = () => {
  return (
    <PageLayout>
      <Container maxWidth="sm">
        <Typography variant="h4" align="center" gutterBottom>
          Whale Chat
        </Typography>
        <Typography align="center">
          This is a gated chat for CYBER.sol whales. Access is granted after verifying
          your wallet holds the required threshold.
        </Typography>
        {/* TODO: integrate actual chat (e.g., using Discord, Matrix, or custom) */}
      </Container>
    </PageLayout>
  );
};

export default WhaleChatPage;
