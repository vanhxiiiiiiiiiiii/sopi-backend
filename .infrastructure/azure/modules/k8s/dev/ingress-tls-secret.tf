resource "kubernetes_secret" "ingress_tls_secret" {
  metadata {
    name = "ingress-tls-secret"

    labels = {
      app = "nginx-ingress"

      component = "ingress"
    }
  }

  data = {
    "tls.crt" = file("${path.module}/tls/tls.crt")

    "tls.key" = file("${path.module}/tls/tls.key")
  }

  type = "kubernetes.io/tls"
}
