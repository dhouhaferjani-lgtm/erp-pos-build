import config from './playwright.config'

export default {
  ...config,
  testMatch: /.*\.smoke\.ts/,
}
