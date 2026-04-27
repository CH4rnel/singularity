import React from 'react';
import { Box, Grid, useMediaQuery } from '@material-ui/core';
import { useTheme } from '@material-ui/core/styles';
import QUICKLogo from 'assets/images/quickLogo.png';
import QUICKLogoWebP from 'assets/images/quickLogo.webp';
import Fire from 'assets/images/fire-new.svg';
import 'components/styles/Footer.scss';
import { Link, useHistory } from 'react-router-dom';
import { useTranslation } from 'react-i18next';

const Footer: React.FC = () => {
  const history = useHistory();
  const copyrightYear = new Date().getFullYear();
  const { t } = useTranslation();
  const theme = useTheme();
  const tabletWindowSize = useMediaQuery(theme.breakpoints.down('sm'));

  const socialMenuItems = [
    {
      title: t('Products'),
      items: [
        { title: t('swap'), link: '/swap' },
        { title: t('pool'), link: '/pools' },
      ],
    },
  ];

  return (
    <Box className='footer'>
      <Box className='footerContainer'>
        <Grid container spacing={4} className='socialMenuWrapper'>
          <Grid item container xs={12} sm={12} md={8} spacing={4}>
            {socialMenuItems.map((item) => (
              <Grid key={item.title} item xs={6} sm={6} md={3}>
                <small style={{ height: '17px' }}>{item.title} &emsp;</small>
                <Box mt={3}>
                  {item.items.map((socialItem: any) => (
                    <Box
                      key={socialItem.title}
                      className='cursor-pointer'
                      my={1.5}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '4px',
                      }}
                      onClick={() => {
                        if (socialItem.link.includes('http')) {
                          window.open(socialItem.link, '_blank');
                        } else {
                          history.push(socialItem.link);
                        }
                      }}
                    >
                      <small className='text-secondary'>
                        {socialItem.title}
                      </small>
                      {socialItem.isNew && <img src={Fire} alt='fire' />}
                    </Box>
                  ))}
                </Box>
              </Grid>
            ))}
          </Grid>
          <Grid item xs={12} sm={12} md={8}>
            <picture>
              <source
                className='logo'
                srcSet={QUICKLogoWebP}
                type='image/webp'
              />
              <img className='logo' src={QUICKLogo} alt='Ritual' />
            </picture>
            <Box mt={2} maxWidth='320px'>
              <small className='text-secondary'>{t('socialDescription')}</small>
            </Box>
          </Grid>
        </Grid>
        <Box
          className={`copyrightWrapper ${
            tabletWindowSize ? 'copyright-mobile' : ''
          }`}
        >
          <Box>
            <small className='text-secondary'>
              © {copyrightYear} Ritual. &nbsp;
            </small>
          </Box>
          <Box>
            <small className='text-secondary'>
              <Link className='footer-link' to='/tos'>
                {t('termsofuse')}
              </Link>
            </small>
          </Box>
        </Box>
      </Box>
    </Box>
  );
};

export default Footer;
