resource "kubernetes_config_map" "api_config" {
  metadata {
    name = "api-config"

    labels = {
      app = "api"

      component = "api"
    }
  }

  data = {
    APP_NAME = "E-commerce"

    APP_ENV = "production"

    APP_DEBUG = "true"

    APP_URL = "https://tp-o.tk"

    FE_URL = "https://e-shopee.vercel.app"

    CORS_ORIGIN = "*"

    DB_CONNECTION = "mysql"

    DB_HOST = "mysql"

    DB_PORT = "3306"

    DB_DATABASE = "ww_db"

    DB_USERNAME = "ww_username"

    MAIL_MAILER = "smtp"

    MAIL_HOST = "smtp.gmail.com"

    MAIL_PORT = "465"

    MAIL_USERNAME = "werewolf.project.pro@gmail.com"

    MAIL_ENCRYPTION = "ssl"

    MAIL_FROM_ADDRESS = "werewolf.project.pro@gmail.com"

    MAIL_FROM_NAME = "E-commerce"
  }
}
